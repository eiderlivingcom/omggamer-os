"""Store page sections -> the meta keys the store's product page reads."""

from app.publishing.runner import section_meta


def test_section_meta_matches_the_store_format() -> None:
    meta = section_meta(
        {
            "edition_includes": ["Base game", "Season pass <DLC>"],
            "activation_steps": ["Open Steam", "Enter the key"],
            "system_requirements": ["## Minimum", "OS: Windows 10", "## Recommended", "OS: Windows 11"],
            "product_pills": ["Steam key", "Instant"],
            "region_warning": "",
            "delivery_note": "Instant, by email",
            "metacritic_score": "87",
        }
    )
    assert meta["omg_whats_included"] == "<ul><li>Base game</li><li>Season pass &lt;DLC&gt;</li></ul>"
    assert meta["omg_activation_steps"] == "<ol><li>Open Steam</li><li>Enter the key</li></ol>"
    assert meta["omg_system_requirements"] == (
        "<p><strong>Minimum</strong></p><ul><li>OS: Windows 10</li></ul>"
        "<p><strong>Recommended</strong></p><ul><li>OS: Windows 11</li></ul>"
    )
    assert meta["omg_pills"] == "Steam key, Instant"
    assert meta["omg_region_warning"] == "" and meta["omg_metacritic"] == "87"
