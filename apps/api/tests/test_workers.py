from sqlalchemy import select

from app.db.models import DeadLetter
from app.db.session import get_sessionmaker
from app.workers.tasks import ping


def test_ping_task_runs() -> None:
    assert "pong" in ping.run()


async def test_failed_task_lands_in_dead_letters() -> None:
    try:
        raise RuntimeError("Kinguin answered 503")
    except RuntimeError as exc:
        ping.on_failure(exc, "task-123", ("a",), {"b": 1}, None)
    async with get_sessionmaker()() as db:
        row = (await db.execute(select(DeadLetter))).scalar_one()
    assert row.task_name == "system.ping" and row.task_id == "task-123"
    assert row.args == {"args": ["a"], "kwargs": {"b": 1}}
    assert "Kinguin answered 503" in row.error
