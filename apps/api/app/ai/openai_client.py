"""Minimal OpenAI Chat Completions client with Structured Outputs (JSON schema), retries and token usage.

Endpoint: POST https://api.openai.com/v1/chat/completions with
response_format = {"type": "json_schema", "json_schema": {"name", "strict": true, "schema"}}.
Usage comes back as usage.prompt_tokens / usage.completion_tokens.
"""

import asyncio
import json
import random
from dataclasses import dataclass
from typing import Any

import httpx

BASE_URL = "https://api.openai.com/v1"
# Looked up at call time so tests can skip real waits.
_sleep = asyncio.sleep


class AiError(Exception):
    temporary = False


class AiAuthError(AiError):
    """Missing or invalid API key."""


class AiQuotaError(AiError):
    """The account is out of credit; retrying will not help until it is topped up."""


class AiRequestError(AiError):
    """The request was rejected (bad model name, bad parameter...)."""


class AiTemporaryError(AiError):
    temporary = True


class AiRefusal(AiError):
    """The model refused or returned no usable content."""


@dataclass
class Completion:
    data: dict[str, Any]
    model: str
    input_tokens: int
    output_tokens: int
    request_id: str | None


def _error_message(r: httpx.Response) -> tuple[str, str | None]:
    try:
        err = r.json().get("error") or {}
        return str(err.get("message") or r.text[:300]), err.get("code") or err.get("type")
    except ValueError:
        return r.text[:300], None


class OpenAIClient:
    def __init__(
        self,
        api_key: str,
        *,
        base_url: str = BASE_URL,
        transport: httpx.AsyncBaseTransport | None = None,
        timeout: float = 120.0,
        max_attempts: int = 4,
    ) -> None:
        if not api_key:
            raise AiAuthError("The OpenAI API key is not set. Add it under Settings → API credentials.")
        self._http = httpx.AsyncClient(
            base_url=base_url.rstrip("/"),
            headers={"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"},
            timeout=timeout,
            transport=transport,
        )
        self.max_attempts = max_attempts

    async def aclose(self) -> None:
        await self._http.aclose()

    async def _post(self, path: str, body: dict[str, Any]) -> httpx.Response:
        last = ""
        for attempt in range(self.max_attempts):
            try:
                r = await self._http.post(path, json=body)
            except (httpx.TimeoutException, httpx.TransportError) as exc:
                last = f"{type(exc).__name__}: {exc}"
                delay = min(30.0, 2.0**attempt) + random.uniform(0, 1)  # noqa: S311 - jitter
            else:
                if r.status_code < 400:
                    return r
                msg, code = _error_message(r)
                if r.status_code == 401:
                    raise AiAuthError(f"OpenAI refused the API key: {msg}")
                if r.status_code == 429 and code == "insufficient_quota":
                    raise AiQuotaError(f"OpenAI account has no remaining quota: {msg}")
                if r.status_code in (400, 403, 404, 422):
                    raise AiRequestError(f"OpenAI rejected the request ({r.status_code}): {msg}")
                last = f"OpenAI {r.status_code}: {msg}"
                retry_after = r.headers.get("retry-after")
                try:
                    delay = min(60.0, float(retry_after)) if retry_after else min(30.0, 2.0**attempt)
                except ValueError:
                    delay = min(30.0, 2.0**attempt)
                delay += random.uniform(0, 1)  # noqa: S311 - jitter
            if attempt + 1 < self.max_attempts:
                await _sleep(delay)
        raise AiTemporaryError(f"OpenAI request failed after {self.max_attempts} attempts: {last}")

    async def structured(
        self,
        *,
        model: str,
        system: str,
        user: str,
        schema_name: str,
        schema: dict[str, Any],
        max_output_tokens: int,
        temperature: float | None,
    ) -> Completion:
        body: dict[str, Any] = {
            "model": model,
            "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
            "response_format": {
                "type": "json_schema",
                "json_schema": {"name": schema_name, "strict": True, "schema": schema},
            },
            "max_completion_tokens": max_output_tokens,
        }
        if temperature is not None:
            body["temperature"] = temperature
        r = await self._post("/chat/completions", body)
        payload = r.json()
        usage = payload.get("usage") or {}
        choice = (payload.get("choices") or [{}])[0]
        message = choice.get("message") or {}
        if message.get("refusal"):
            raise AiRefusal(f"The model refused: {message['refusal']}")
        if choice.get("finish_reason") == "length":
            raise AiRefusal("The answer was cut off at the output token limit; raise Maximum output tokens.")
        try:
            data = json.loads(message.get("content") or "")
        except ValueError as exc:
            raise AiRefusal("The model returned text that is not valid JSON") from exc
        if not isinstance(data, dict):
            raise AiRefusal("The model returned JSON that is not an object")
        return Completion(
            data=data,
            model=str(payload.get("model") or model),
            input_tokens=int(usage.get("prompt_tokens") or 0),
            output_tokens=int(usage.get("completion_tokens") or 0),
            request_id=r.headers.get("x-request-id"),
        )

    async def check(self) -> int:
        """Cheap authenticated call used by 'Test connection'. Returns the number of models visible."""
        last = ""
        for attempt in range(self.max_attempts):
            try:
                r = await self._http.get("/models")
            except (httpx.TimeoutException, httpx.TransportError) as exc:
                last = str(exc)
            else:
                if r.status_code == 200:
                    return len(r.json().get("data") or [])
                msg, _ = _error_message(r)
                if r.status_code == 401:
                    raise AiAuthError(f"OpenAI refused the API key: {msg}")
                if r.status_code < 500 and r.status_code != 429:
                    raise AiRequestError(f"OpenAI answered {r.status_code}: {msg}")
                last = f"{r.status_code}: {msg}"
            if attempt + 1 < self.max_attempts:
                await _sleep(min(10.0, 2.0**attempt))
        raise AiTemporaryError(f"Could not reach OpenAI: {last}")
