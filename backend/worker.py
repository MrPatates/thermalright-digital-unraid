#!/usr/bin/env python3
"""Unraid host metrics -> unchanged TRCC 9.11.2 LED transport (GPL-3.0+)."""
import json
import colorsys
import logging
from logging.handlers import RotatingFileHandler
import math
import os
from pathlib import Path
import signal
import threading
import time

from trcc.adapters.device.led import Led
from trcc.adapters.device.transport import PyUsbBulkTransport
from trcc.core.models import ProductInfo, Wire, Kind, HardwareMetrics, LedStyle
from trcc.core.led_models import LedPayload
from trcc.services.led_segment import get_display

STOP = threading.Event()
BLACK = (0, 0, 0)
DEFAULT_COLOR = '#00b4ff'
ZONE_NAMES = ('temperature', 'load', 'power', 'frequency', 'decoration')
SUPPORTED = (LedStyle.PA120, LedStyle.AX120, LedStyle.AK120, LedStyle.LF8,
             LedStyle.LF12, LedStyle.LF15, LedStyle.LF10, LedStyle.CZ1, LedStyle.MAGIC_QUBE)


def layout_for(style):
    """Accept only upstream layouts whose absent-value groups are mapped."""
    if style not in SUPPORTED:
        raise ValueError(f'Style {style!s} unsupported by this adapter; no digits sent')
    return get_display(style)


def flatten(groups):
    return tuple(i for group in groups for i in group)


def layout_zones(style, tick=0):
    """Physical groups come from the pinned TRCC driver, never from USB IDs."""
    d = layout_for(style)
    zones = {name: () for name in ZONE_NAMES}
    if style == LedStyle.PA120:
        zones.update(temperature=d.ZONE_LEDS[0], load=d.ZONE_LEDS[1])
    elif style in (LedStyle.AX120, LedStyle.CZ1):
        zones['temperature' if tick % 2 == 0 else 'load'] = tuple(range(d.mask_size))
    elif style == LedStyle.MAGIC_QUBE:
        zones['temperature' if tick % 2 == 0 else 'load'] = tuple(range(50))
        zones['decoration'] = d.BORDER
    elif style == LedStyle.LF10:
        zones['temperature'] = (d.CPU1, d.SSD, d.HSD, *flatten(d.DIGIT_LEDS_13[:3]))
        zones['decoration'] = d.DECORATION
    else:
        zones.update(temperature=(*flatten(d.TEMP_DIGITS), d.SSD, d.HSD),
                     load=(*flatten(d.USE_DIGITS), *d.USE_PARTIAL, d.BFB),
                     power=(*flatten(d.WATT_DIGITS), d.WATT))
        if hasattr(d, 'MHZ_DIGITS'):
            zones['frequency'] = (*flatten(d.MHZ_DIGITS), d.MHZ)
        zones['decoration'] = getattr(d, 'DECORATION', ())
        # The CPU label is shared by the four CPU metrics, not a decoration.
        zones['temperature'] = (*zones['temperature'], d.CPU1)
    return zones


def capabilities_for(style):
    zones = layout_zones(style)
    # A rotating display can colour each metric even when it has one group.
    alternate = layout_zones(style, 1)
    names = [name for name in ZONE_NAMES if zones[name] or alternate[name]]
    return dict(power=bool(zones['power']), frequency=bool(zones['frequency']),
                zones=len(names) > 1, decoration=bool(zones['decoration']), zone_names=names)


def displayed_values(style, sample):
    """Blank values the physical digits cannot represent instead of clamping."""
    two_digit = style in (LedStyle.CZ1, LedStyle.MAGIC_QUBE)
    limits = {'cpu_temp': 99 if two_digit else 999,
              'cpu_load': 99 if two_digit else 199, 'cpu_watts': 999, 'cpu_mhz': 9999}
    values, unavailable = {}, []
    for key, high in limits.items():
        value = sample.get(key)
        if value is not None and (type(value) not in (int, float) or
                                  not math.isfinite(value) or not 0 <= value <= high):
            unavailable.append(key)
            value = None
        values[key] = value
    return values, unavailable


def mask_for(style, sample, tick=0):
    """Zero only builds an intermediate mask; missing groups are then erased."""
    display = layout_for(style)
    values, _ = displayed_values(style, sample)
    temp, load = values['cpu_temp'], values['cpu_load']
    metrics = HardwareMetrics(cpu_temp=temp or 0.0, cpu_percent=load or 0.0,
                              cpu_power=values['cpu_watts'] or 0.0, cpu_freq=values['cpu_mhz'] or 0.0)
    phase = tick % 2 if style in (LedStyle.AX120, LedStyle.CZ1) else 0
    if style == LedStyle.MAGIC_QUBE:
        phase = (0, 3)[tick % 2]  # upstream order is CPU temp, GPU temp, GPU load, CPU load
    mask = display.compute_mask(metrics, phase=phase)

    def clear(indices):
        for i in indices:
            mask[i] = False

    if style == LedStyle.PA120:
        clear(display.ZONE_LEDS[2] + display.ZONE_LEDS[3])  # no GPU source
        if temp is None:
            clear(display.ZONE_LEDS[0])
        if load is None:
            clear(display.ZONE_LEDS[1])
    elif style in (LedStyle.AX120, LedStyle.CZ1, LedStyle.MAGIC_QUBE):
        if (tick % 2 == 0 and temp is None) or (tick % 2 == 1 and load is None):
            mask = [False] * display.mask_size
    elif style == LedStyle.LF10:
        clear((display.GPU1, display.SSD1, display.HSD1, *flatten(display.DIGIT_LEDS_13[3:])))
        if temp is None:
            clear(layout_zones(style)['temperature'])
    else:  # AK120 or LF8/LF12/LF15, fixed CPU page
        if values['cpu_watts'] is None:
            clear((*flatten(display.WATT_DIGITS), display.WATT))
        if hasattr(display, 'MHZ_DIGITS') and values['cpu_mhz'] is None:
            clear((*flatten(display.MHZ_DIGITS), display.MHZ))
        if temp is None:
            clear((*flatten(display.TEMP_DIGITS), display.SSD, display.HSD))
        if load is None:
            clear((*flatten(display.USE_DIGITS), *display.USE_PARTIAL, display.BFB))
        if all(values[key] is None for key in ('cpu_temp', 'cpu_load', 'cpu_watts', 'cpu_mhz')):
            clear((display.CPU1,))
    return mask


def parse_color(value):
    if (not isinstance(value, str) or len(value) != 7 or value[0] != '#' or
        any(ch not in '0123456789abcdefABCDEF' for ch in value[1:])):
        raise ValueError('Invalid RGB color')
    try:
        return tuple(int(value[i:i + 2], 16) for i in (1, 3, 5))
    except ValueError:
        raise ValueError('Invalid RGB color') from None


def rgb_settings(raw=None):
    """Defensive parsing: the host validates settings before publishing them."""
    raw = {} if raw is None else raw
    if not isinstance(raw, dict):
        raise ValueError('Invalid RGB settings')
    cfg = dict(mode=raw.get('mode', 'static'), color=raw.get('color', DEFAULT_COLOR),
               brightness=raw.get('brightness', 100), period=raw.get('period', 6),
               target=raw.get('target', 'all'), thresholds=raw.get('thresholds', [40, 60, 80]),
               temperature_zones=raw.get('temperature_zones', list(ZONE_NAMES)),
               temperature_colors=raw.get('temperature_colors', ['#00b4ff', '#00ff00', '#ff9900', '#ff0000']),
               smoothing=raw.get('smoothing', 3),
               zone_colors=raw.get('zone_colors', {name: DEFAULT_COLOR for name in ZONE_NAMES}))
    if cfg['mode'] not in ('static', 'zones', 'temperature', 'breathing', 'rainbow'):
        raise ValueError('Invalid RGB mode')
    if cfg['target'] not in ('all', 'digits', 'decoration'):
        raise ValueError('Invalid RGB target')
    temperature_zones = cfg['temperature_zones']
    if (not isinstance(temperature_zones, list) or not 1 <= len(temperature_zones) <= len(ZONE_NAMES) or
        any(not isinstance(zone, str) or zone not in ZONE_NAMES for zone in temperature_zones) or
        len(set(temperature_zones)) != len(temperature_zones)):
        raise ValueError('Invalid temperature categories')
    for key, low, high in (('brightness', 0, 100), ('period', 1, 60), ('smoothing', 0, 30)):
        value = cfg[key]
        if type(value) not in (int, float) or not math.isfinite(value) or not low <= value <= high:
            raise ValueError(f'Invalid RGB setting: {key}')
    thresholds = cfg['thresholds']
    if (not isinstance(thresholds, list) or len(thresholds) != 3 or
        any(type(v) not in (int, float) or not math.isfinite(v) or not 0 <= v <= 125 for v in thresholds) or
        not thresholds[0] < thresholds[1] < thresholds[2]):
        raise ValueError('Invalid RGB thresholds')
    if not isinstance(cfg['temperature_colors'], list) or len(cfg['temperature_colors']) != 4:
        raise ValueError('Invalid temperature colors')
    if not isinstance(cfg['zone_colors'], dict):
        raise ValueError('Invalid zone colors')
    cfg['zone_colors'] = {name: cfg['zone_colors'].get(name, DEFAULT_COLOR) for name in ZONE_NAMES}
    for color in [cfg['color'], *cfg['temperature_colors'], *cfg['zone_colors'].values()]:
        parse_color(color)
    return cfg


def temperature_color(temp, cfg):
    """Four colour stops: cold, first threshold, second, third and beyond."""
    colors = [parse_color(color) for color in cfg['temperature_colors']]
    if type(temp) not in (int, float) or not math.isfinite(temp):
        return parse_color(cfg['color'])
    a, b, c = cfg['thresholds']
    stops = [a - (b - a), a, b, c]
    if temp <= stops[0]:
        return colors[0]
    for i in range(1, 4):
        if temp < stops[i]:
            ratio = (temp - stops[i - 1]) / (stops[i] - stops[i - 1])
            return tuple(x + (y - x) * ratio for x, y in zip(colors[i - 1], colors[i]))
    return colors[-1]


class RgbEngine:
    """Animate colours using monotonic time; numeric masks stay unsmoothed."""
    def __init__(self):
        self.temperature = None
        self.updated_at = None

    def render(self, style, mask, sample, tick, cfg, now):
        zones = layout_zones(style, tick)
        zone_at = {index: name for name, indices in zones.items() for index in indices}
        base = parse_color(cfg['color'])
        heat = temperature_color(sample.get('cpu_temp'), cfg)
        if cfg['mode'] == 'temperature' and sample.get('cpu_temp') is not None:
            if self.temperature is None or self.updated_at is None or cfg['smoothing'] == 0:
                self.temperature = heat
            else:
                elapsed = max(0, now - self.updated_at)
                alpha = 1 - math.exp(-elapsed / cfg['smoothing'])
                self.temperature = tuple(x + (y - x) * alpha for x, y in zip(self.temperature, heat))
            heat = self.temperature
            self.updated_at = now
        else:
            self.temperature, self.updated_at = None, None
        # A digit shares one rainbow hue: seven differently coloured segments
        # would obscure its value. Decorative LEDs keep their spatial gradient.
        d = layout_for(style)
        digit_groups = []
        for attr in ('CPU_TEMP_DIGITS', 'CPU_USE_DIGITS', 'GPU_TEMP_DIGITS', 'GPU_USE_DIGITS',
                     'DIGITS', 'TEMP_DIGITS', 'WATT_DIGITS', 'MHZ_DIGITS', 'USE_DIGITS', 'DIGIT_LEDS_13'):
            digit_groups.extend(getattr(d, attr, ()))
        if style == LedStyle.MAGIC_QUBE:
            digit_groups.extend((tuple(range(21)), tuple(range(21, 42))))
        slots = {index: sum(group) / len(group) for group in digit_groups for index in group}
        brightness = cfg['brightness'] / 100
        colors = []
        for index, on in enumerate(mask):
            if not on:
                colors.append(BLACK)
                continue
            zone = zone_at.get(index, 'temperature')
            affected = (cfg['target'] == 'all' or
                        (cfg['target'] == 'decoration') == (zone == 'decoration'))
            # Temperature is an effect applied to selected metric categories.
            # Other categories retain their independently configured colours.
            color = parse_color(cfg['zone_colors'][zone]) if cfg['mode'] == 'temperature' else base
            if affected:
                if cfg['mode'] == 'zones':
                    color = parse_color(cfg['zone_colors'][zone])
                elif cfg['mode'] == 'temperature' and zone in cfg['temperature_zones']:
                    color = heat
                elif cfg['mode'] == 'breathing':
                    level = 0.15 + 0.85 * (math.sin(2 * math.pi * (now % cfg['period']) / cfg['period']) + 1) / 2
                    color = tuple(v * level for v in base)
                elif cfg['mode'] == 'rainbow':
                    hue = ((now % cfg['period']) / cfg['period'] + slots.get(index, index) / len(mask) * 0.25) % 1
                    color = tuple(v * 255 for v in colorsys.hsv_to_rgb(hue, 1, 1))
            colors.append(tuple(round(v * brightness) for v in color))
        return colors


def colors_for(style, sample, tick=0, *, now=None, engine=None, rgb=None):
    cfg = rgb_settings(sample.get('rgb') if rgb is None else rgb)
    return (engine or RgbEngine()).render(style, mask_for(style, sample, tick), sample, tick,
                                         cfg, time.monotonic() if now is None else now)


class DisplayTest:
    """A bounded RAM-only visual test; wall-clock changes cannot prolong it."""
    def __init__(self):
        self.id = None
        self.deadline = 0
        self.duration = 0

    def frame(self, descriptor, size, wall_now, mono_now):
        inactive = dict(active=False, id=None, phase=None, remaining=0)
        if not isinstance(descriptor, dict):
            return None, inactive
        identifier, started = descriptor.get('id'), descriptor.get('started_at')
        duration = descriptor.get('duration', 8)
        if (not isinstance(identifier, str) or not 1 <= len(identifier) <= 128 or
            type(started) not in (int, float) or not math.isfinite(started) or
            type(duration) not in (int, float) or not math.isfinite(duration) or duration <= 0):
            return None, inactive
        duration = min(duration, 15)
        age = wall_now - started
        if not 0 <= age < duration:
            return None, inactive
        if identifier != self.id:
            self.id, self.duration = identifier, duration
            self.deadline = mono_now + duration - age
        remaining = max(0, min(self.duration, self.deadline - mono_now))
        if remaining <= 0:
            return None, inactive
        stage = min(3, int((self.duration - remaining) / self.duration * 4))
        phase = ('rouge', 'vert', 'bleu', 'segments')[stage]
        color = ((255, 0, 0), (0, 255, 0), (0, 0, 255), (255, 255, 255))[stage]
        return [color] * size, dict(active=True, id=self.id, phase=phase, remaining=round(remaining, 1))


def read_sample(path, now=None):
    sample = json.loads(Path(path).read_text())
    now = time.time() if now is None else now
    if (not isinstance(sample, dict) or type(sample.get('timestamp')) not in (float, int) or
        not math.isfinite(sample['timestamp']) or not 0 <= now - sample['timestamp'] <= 10):
        raise ValueError('Stale host metrics')
    for key, low, high in [('cpu_temp', -20, 150), ('cpu_load', 0, 100),
                            ('cpu_watts', 0, 100000), ('cpu_mhz', 0, 1000000)]:
        value = sample.get(key)
        if value is not None and (type(value) not in (float, int) or
                                  not math.isfinite(value) or not low <= value <= high):
            raise ValueError(f'Invalid host metric: {key}')
    return sample


def write_status(**data):
    path = Path('/state/status.json')
    temp = path.with_suffix('.tmp')
    temp.write_text(json.dumps(dict(timestamp=time.time(), **data)))
    temp.replace(path)


def main():
    handler = RotatingFileHandler('/state/driver.log', maxBytes=262144, backupCount=1)
    handler.setFormatter(logging.Formatter('%(asctime)s %(levelname)s %(message)s'))
    logging.basicConfig(level=logging.INFO, handlers=[handler])
    for sig in (signal.SIGTERM, signal.SIGINT):
        signal.signal(sig, lambda *_: STOP.set())
    interval = int(os.environ.get('TD_INTERVAL', '1'))
    if not 1 <= interval <= 60:
        raise ValueError('Intervalle hors limites')
    info = ProductInfo(0x0416, 0x8001, 'Thermalright', 'Digital', Wire.LED, Kind.LED)
    device = Led(info, PyUsbBulkTransport(0x0416, 0x8001))
    device.set_state_dir(Path('/state'))  # handshake cache survives container recreation, in RAM
    last_colors = None
    try:
        device.connect()
        handshake = device.led_handshake
        style = handshake.style
        # Validate a supported layout before sending a metric packet.
        colors_for(style, {})
        logging.info('Detected model: %s, PM=%s, style=%s', handshake.model_name, handshake.pm, style)
        started = time.monotonic()
        engine, visual_test = RgbEngine(), DisplayTest()
        capabilities = capabilities_for(style)
        while not STOP.is_set():
            frame_started = time.monotonic()
            tick = int(max(0, frame_started - started) / interval)
            test_status = dict(active=False, id=None, phase=None, remaining=0)
            try:
                sample = read_sample('/host/metrics.json')
                cfg = rgb_settings(sample.get('rgb'))
                colors = colors_for(style, sample, tick, now=frame_started, engine=engine, rgb=cfg)
                test_colors, test_status = visual_test.frame(sample.get('display_test'), len(colors),
                                                             time.time(), frame_started)
                if test_colors is not None:
                    # This pattern is explicitly reported as a test; no fake
                    # value is ever substituted into the measured status.
                    colors = test_colors
                error = None
            except (ValueError, OSError, json.JSONDecodeError) as exc:
                sample = {}
                cfg = rgb_settings()
                colors = [BLACK] * layout_for(style).mask_size
                error = str(exc)
            # Remember the attempted frame as well: a failed transfer may
            # have partially reached USB and still needs an all-off cleanup.
            last_colors = colors
            if not device.send(LedPayload(colors=colors)):
                raise RuntimeError('USB transmission failed')
            write_status(ready=True, model=handshake.model_name, style=style.value,
                         cpu_temp=sample.get('cpu_temp'), cpu_load=sample.get('cpu_load'),
                         cpu_watts=sample.get('cpu_watts'), cpu_mhz=sample.get('cpu_mhz'),
                         capabilities=capabilities, rgb=cfg, test_active=test_status['active'],
                         display_test=test_status, display_out_of_range=displayed_values(style, sample)[1],
                         error=error)
            STOP.wait(max(0, 0.1 - (time.monotonic() - frame_started)))
    except Exception as exc:
        logging.exception('Backend stopped')
        write_status(ready=False, error=str(exc))
        return 1
    finally:
        try:
            if last_colors is not None:
                device.send(LedPayload(colors=[(0, 0, 0)] * len(last_colors), global_on=False))
        finally:
            device.disconnect()
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
