import pytest

from app.catalogue.normalize import base_title, match_key, payload_checksum, slugify, variant_key


@pytest.mark.parametrize(
    ("title", "key"),
    [
        ("Star Wars Outlaws Gold Edition (PS5) Digital Key (Global)", "star wars outlaws"),
        ("Star Wars Outlaws Standard Edition ( Xbox Series X|S) Digital Key (Global)", "star wars outlaws"),
        ("Star Wars Outlaws: Ultimate Edition PC Steam Account", "star wars outlaws"),
        ("Star Wars Outlaws PS5 Account", "star wars outlaws"),
        ("Grand Theft Auto V Enhanced PC Epic Games Account", "grand theft auto v enhanced"),
        (
            "Europa Universalis IV \u2013 Golden Century DLC PC Steam CD Key",
            "europa universalis iv golden century",
        ),
        ("Roblox - 200 Robux (Global) - Digital Key", "roblox 200 robux"),
        ("The Witcher® 3: Wild Hunt GOTY Edition GOG CD Key", "the witcher 3 wild hunt"),
    ],
)
def test_match_key_groups_variants(title: str, key: str) -> None:
    assert match_key(title) == key


def test_base_title_is_readable() -> None:
    assert base_title("Star Wars Outlaws: Ultimate Edition PC Steam Account") == "Star Wars Outlaws"


def test_slugify_and_variant_key() -> None:
    assert slugify("Pokémon™ Legends: Arceus!") == "pokemon-legends-arceus"
    assert slugify("") == "item"
    assert variant_key("Steam", None, "Global", "Key", None) == "steam||global|key|"
    assert variant_key("STEAM ", None, "global", "key", None) == variant_key(
        "Steam", None, "Global", "Key", None
    )


def test_checksum_ignores_key_order() -> None:
    assert payload_checksum({"a": 1, "b": [1, 2]}) == payload_checksum({"b": [1, 2], "a": 1})
    assert payload_checksum({"a": 1}) != payload_checksum({"a": 2})
