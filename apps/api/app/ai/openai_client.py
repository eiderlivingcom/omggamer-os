"""Minimal OpenAI-compatible Chat Completions client with JSON output, retries and token usage.

Works with OpenAI and with OpenAI-compatible gateways such as OmniRoute: POST {base}/chat/completions.
Structured output is requested with response_format = {"type": "json_schema", ...}. Many free models do not
support that, so json_mode "auto" falls back to {"type": "json_object"} with the schema written into the
instructions, and the reply is parsed leniently (code fences and text around the object are ignored).
Usage comes back as usage.prompt_tokens / usage.completion_tokens.
"""

import asyncio
import json
import random
import re
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


_FENCE = re.compile(r"^```(?:json)?\s*|\s*```$", re.IGNORECASE)
_SCHEMA_UNSUPPORTED = ("response_format", "json_schema", "schema", "strict", "structured")


def parse_json_object(text: str) -> dict[str, Any]:
    """The JSON object in a model reply, tolerating code fences and text around it."""
    t = _FENCE.sub("", (text or "").strip()).strip()
    try:
        data = json.loads(t)
    except ValueError:
        start, end = t.find("{"), t.rfind("}")
        if start < 0 or end <= start:
            raise AiRefusal("The model returned text that is not valid JSON") from None
        try:
            data = json.loads(t[start : end + 1])
        except ValueError as exc:
            raise AiRefusal("The model returned text that is not valid JSON") from exc
    if not isinstance(data, dict):
        raise AiRefusal("The model returned JSON that is not an object")
    return data


class OpenAIClient:
    def __init__(
        self,
        api_key: str,
        *,
        base_url: str = BASE_URL,
        label: str = "OpenAI",
        json_mode: str = "schema",
        transport: httpx.AsyncBaseTransport | None = None,
        timeout: float = 120.0,
        max_attempts: int = 4,
    ) -> None:
        self.label = label
        self.json_mode = json_mode if json_mode in ("auto", "schema", "json_object") else "auto"
        self.is_openai = base_url.rstrip("/") == BASE_URL
        if not api_key:
            raise AiAuthError(f"The {label} API key is not set. Add it under Settings → API credentials.")
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
                    raise AiAuthError(f"{self.label} refused the API key: {msg}")
                if r.status_code == 429 and code == "insufficient_quota":
                    raise AiQuotaError(f"{self.label} account has no remaining quota: {msg}")
                if r.status_code in (400, 403, 404, 422):
                    raise AiRequestError(f"{self.label} rejected the request ({r.status_code}): {msg}")
                last = f"{self.label} {r.status_code}: {msg}"
                retry_after = r.headers.get("retry-after")
                try:
                    delay = min(60.0, float(retry_after)) if retry_after else min(30.0, 2.0**attempt)
                except ValueError:
                    delay = min(30.0, 2.0**attempt)
                delay += random.uniform(0, 1)  # noqa: S311 - jitter
            if attempt + 1 < self.max_attempts:
                await _sleep(delay)
        raise AiTemporaryError(f"{self.label} request failed after {self.max_attempts} attempts: {last}")

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
        args = dict(
            model=model,
            system=system,
            user=user,
            schema_name=schema_name,
            schema=schema,
            max_output_tokens=max_output_tokens,
            temperature=temperature,
        )
        if self.json_mode == "json_object":
            return await self._complete(**args, use_schema=False)
        try:
            return await self._complete(**args, use_schema=True)
        except AiRequestError as exc:
            # The model or gateway does not accept JSON schema output: ask for a plain JSON object instead.
            if self.json_mode != "auto" or not any(w in str(exc).lower() for w in _SCHEMA_UNSUPPORTED):
                raise
        except AiRefusal as exc:
            if self.json_mode != "auto" or "json" not in str(exc).lower():
                raise
        return await self._complete(**args, use_schema=False)

    async def _complete(
        self,
        *,
        model: str,
        system: str,
        user: str,
        schema_name: str,
        schema: dict[str, Any],
        max_output_tokens: int,
        temperature: float | None,
        use_schema: bool,
    ) -> Completion:
        if use_schema:
            response_format: dict[str, Any] = {
                "type": "json_schema",
                "json_schema": {"name": schema_name, "strict": True, "schema": schema},
            }
        else:
            response_format = {"type": "json_object"}
            system = (
                f"{system}\n\nReply with one JSON object only, no other text, matching this JSON schema "
                f"(every property is required):\n{json.dumps(schema, separators=(',', ':'))}"
            )
        body: dict[str, Any] = {
            "model": model,
            "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
            "response_format": response_format,
        }
        # OpenAI's newer models want max_completion_tokens; OpenAI-compatible gateways expect max_tokens.
        body["max_completion_tokens" if self.is_openai else "max_tokens"] = max_output_tokens
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
        content = message.get("content")
        if isinstance(content, list):  # some gateways return content parts
            content = "".join(str(p.get("text") or "") for p in content if isinstance(p, dict))
        data = parse_json_object(str(content or ""))
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
                    raise AiAuthError(f"{self.label} refused the API key: {msg}")
                if r.status_code < 500 and r.status_code != 429:
                    raise AiRequestError(f"{self.label} answered {r.status_code}: {msg}")
                last = f"{r.status_code}: {msg}"
            if attempt + 1 < self.max_attempts:
                await _sleep(min(10.0, 2.0**attempt))
        raise AiTemporaryError(f"Could not reach {self.label}: {last}")
