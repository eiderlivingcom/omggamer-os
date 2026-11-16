"""Product types: in-game currency ("game cash") is told apart from games, gift cards and accounts."""

import pytest

from app.catalogue.normalize import is_game_currency
from app.suppliers.foxreload.normalizer import product_type_for as fox_type
from app.suppliers.kinguin.normalizer import product_type_for as kin_type


@pytest.mark.parametrize(
    ("title", "tags", "expected"),
    [
        ("Fortnite 800 V-Bucks US Epic Games CD Key", [], "game_currency"),
        ("Roblox Game eCard 1430 Robux CA", ["prepaid"], "game_currency"),
        ("VALORANT - 825 Valorant Points Gift Card TR", ["prepaid"], "game_currency"),
        (
            "Grand Theft Auto V + Great White Shark Cash Card RU/CIS PC Rockstar Key",
            ["base", "prepaid"],
            "game_currency",
        ),
        ("PUBG Mobile - 6000 + 2100 UC EG CD Key", [], "game_currency"),
        ("EA SPORTS FC 26 - 1050 FC Points US XBOX One CD Key", [], "game_currency"),
        ("Steam USD 500 Top Up CIS", ["prepaid"], "gift_card"),
        ("PayPal Wallet 250 USD Top Up US", ["prepaid"], "gift_card"),
        ("Fortnite - 5000 V-Bucks Epic Games Account", [], "game_account"),
        ("Fortnite - Transformers Pack + 1000 VBucks DLC EU", ["dlc"], "dlc"),
        ("Call of Duty: Black Ops 6 Gold Edition PC Steam Key", [], "game"),
        ("Cash Cleaner Simulator PC Steam CD Key", ["base"], "game"),
    ],
)
def test_kinguin_types(title: str, tags: list[str], expected: str) -> None:
    assert kin_type(title, tags) == expected


def test_foxreload_and_helper() -> None:
    assert fox_type("Apex Legends - 6700 Apex Coins XBOX One", {}) == "game_currency"
    assert fox_type("Apex Legends - 6700 Apex Coins", {"steam_app_type": "dlc"}) == "dlc"
    assert not is_game_currency("The Elder Scrolls V: Skyrim Gold Edition")
