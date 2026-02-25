from unittest.mock import patch
from scripts.sun_info import main
from suntime import SunTimeException


def test_sun_info_sunrise():
    # Normal lat/lon should return a valid HH:MM string
    result = main(51.11, 8.96, "up")
    assert len(result) == 5
    assert result[2] == ":"
    hour, minute = result.split(":")
    assert 0 <= int(hour) <= 23
    assert 0 <= int(minute) <= 59


def test_sun_info_sunset():
    result = main(51.11, 8.96, "down")
    assert len(result) == 5
    assert result[2] == ":"
    hour, minute = result.split(":")
    assert 0 <= int(hour) <= 23
    assert 0 <= int(minute) <= 59


def test_sun_info_fallback_on_exception():
    # When SunTimeException is raised, should fall back to "06:00"/"18:00"
    with patch('scripts.sun_info.Sun') as MockSun:
        instance = MockSun.return_value
        instance.get_local_sunrise_time.side_effect = SunTimeException("test error")
        instance.get_local_sunset_time.side_effect = SunTimeException("test error")

        result_up = main(90.0, 0.0, "up")
        assert result_up == "06:00"

        result_down = main(90.0, 0.0, "down")
        assert result_down == "18:00"
