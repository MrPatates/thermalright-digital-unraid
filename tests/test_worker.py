import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock
import logging
import math
from trcc.core.models import LedStyle, HardwareMetrics

spec = importlib.util.spec_from_file_location('worker', '/work/backend/worker.py')
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class MetricsTests(unittest.TestCase):
    def test_pa120_unknown_gpu_and_cpu_are_blank(self):
        self.assertFalse(any(any(c) for c in worker.colors_for(LedStyle.PA120, {})))

    def test_pa120_real_cpu_and_no_gpu(self):
        colors = worker.colors_for(LedStyle.PA120, {'cpu_temp': 54, 'cpu_load': 37})
        self.assertTrue(any(any(c) for c in colors[:45]))
        display = worker.get_display(LedStyle.PA120)
        for index in display.ZONE_LEDS[2] + display.ZONE_LEDS[3]:
            self.assertEqual(colors[index], (0, 0, 0))

    def test_missing_temp_preserves_load(self):
        display = worker.get_display(LedStyle.PA120)
        colors = worker.colors_for(LedStyle.PA120, {'cpu_temp': None, 'cpu_load': 0})
        self.assertTrue(any(any(colors[i]) for i in display.ZONE_LEDS[1]))  # measured 0% IS valid
        self.assertFalse(any(any(colors[i]) for i in display.ZONE_LEDS[0]))

    def test_ax120_rotates_only_host_cpu(self):
        self.assertEqual(worker.colors_for(LedStyle.AX120, {'cpu_temp': 54}, 0),
                         worker.colors_for(LedStyle.AX120, {'cpu_temp': 54}, 2))
        self.assertFalse(any(any(c) for c in worker.colors_for(LedStyle.AX120, {}, 0)))

    def test_ak120_unavailable_power_is_blank(self):
        display = worker.get_display(LedStyle.AK120)
        colors = worker.colors_for(LedStyle.AK120, {'cpu_temp': 55, 'cpu_load': 8})
        for group in display.WATT_DIGITS:
            for i in group:
                self.assertEqual(colors[i], (0, 0, 0))

    def test_unknown_layout_fails_before_sending_numbers(self):
        with self.assertRaises(ValueError):
            worker.colors_for(LedStyle.LC1, {})

    def test_additional_cpu_layouts_suppress_unknown_fields(self):
        for style in (LedStyle.LF8, LedStyle.LF12, LedStyle.LF15):
            display = worker.get_display(style)
            colors = worker.colors_for(style, {})
            for attr in ('TEMP_DIGITS', 'USE_DIGITS', 'WATT_DIGITS', 'MHZ_DIGITS'):
                for group in getattr(display, attr):
                    for index in group:
                        self.assertEqual(colors[index], (0, 0, 0), (style, attr, index))
        for style in (LedStyle.CZ1, LedStyle.MAGIC_QUBE):
            for tick in range(4):
                self.assertFalse(any(any(c) for c in worker.colors_for(style, {}, tick)))
        display = worker.get_display(LedStyle.LF10)
        colors = worker.colors_for(LedStyle.LF10, {})
        for group in display.DIGIT_LEDS_13:
            for index in group:
                self.assertEqual(colors[index], (0, 0, 0))

    def test_main_transmits_then_clears_and_disconnects(self):
        device = Mock()
        device.led_handshake.style = LedStyle.PA120
        device.led_handshake.model_name = 'test'
        stopper = Mock()
        stopper.is_set.side_effect = [False, True]
        with patch.object(worker, 'Led', return_value=device), \
             patch.object(worker, 'STOP', stopper), \
             patch.object(worker.signal, 'signal'), \
             patch.object(worker, 'RotatingFileHandler', return_value=logging.NullHandler()), \
             patch.object(worker, 'read_sample', return_value={'cpu_temp': 54, 'cpu_load': 21}), \
             patch.object(worker, 'write_status') as status:
            self.assertEqual(worker.main(), 0)
            self.assertEqual(status.call_args.kwargs['style'], 'pa120')
            device.connect.assert_called_once()
            device.disconnect.assert_called_once()
            self.assertEqual(device.send.call_count, 2)
            self.assertFalse(device.send.call_args.args[0].global_on)

    def test_stale_and_invalid_metrics_are_rejected(self):
        with tempfile.TemporaryDirectory() as d:
            p = Path(d) / 'metrics.json'
            for s in [{'timestamp': 1}, {'timestamp': 100, 'cpu_temp': -99},
                      {'timestamp': 100, 'cpu_load': 101}, {'timestamp': 100, 'cpu_temp': True}]:
                p.write_text(json.dumps(s))
                with self.assertRaises(ValueError):
                    worker.read_sample(p, now=100)
            p.write_text(json.dumps({'timestamp': 100, 'cpu_temp': None, 'cpu_load': 0}))
            self.assertIsNone(worker.read_sample(p, now=105)['cpu_temp'])

    def test_real_power_and_frequency_use_the_upstream_digit_layout(self):
        sample = {'cpu_temp': 54, 'cpu_load': 37, 'cpu_watts': 27.4, 'cpu_mhz': 5500}
        metrics = HardwareMetrics(cpu_temp=54, cpu_percent=37, cpu_power=27.4, cpu_freq=5500)
        for style in (LedStyle.AK120, LedStyle.LF8, LedStyle.LF12, LedStyle.LF15):
            with self.subTest(style=style):
                display = worker.get_display(style)
                self.assertEqual(worker.mask_for(style, sample), display.compute_mask(metrics, phase=0))
                colors = worker.colors_for(style, sample)
                self.assertNotEqual(colors[display.WATT], worker.BLACK)
                if hasattr(display, 'MHZ'):
                    self.assertNotEqual(colors[display.MHZ], worker.BLACK)

    def test_measured_zero_power_and_frequency_are_displayed(self):
        d = worker.get_display(LedStyle.LF8)
        colors = worker.colors_for(LedStyle.LF8, {'cpu_watts': 0, 'cpu_mhz': 0})
        self.assertNotEqual(colors[d.WATT], worker.BLACK)
        self.assertNotEqual(colors[d.MHZ], worker.BLACK)
        self.assertTrue(any(any(colors[i]) for i in d.WATT_DIGITS[-1]))
        self.assertTrue(any(any(colors[i]) for i in d.MHZ_DIGITS[-1]))

    def test_missing_and_overflow_values_blank_digits_and_units_without_clamping(self):
        d = worker.get_display(LedStyle.LF12)
        for watts, mhz in ((None, None), (1000, 10000), (float('nan'), float('inf'))):
            with self.subTest(watts=watts, mhz=mhz):
                colors = worker.colors_for(LedStyle.LF12, {'cpu_temp': 54, 'cpu_load': 0,
                                                         'cpu_watts': watts, 'cpu_mhz': mhz})
                for attr, unit in (('WATT_DIGITS', d.WATT), ('MHZ_DIGITS', d.MHZ)):
                    self.assertEqual(colors[unit], worker.BLACK)
                    self.assertTrue(all(colors[i] == worker.BLACK for i in worker.flatten(getattr(d, attr))))
                self.assertTrue(any(any(colors[i]) for i in worker.flatten(d.TEMP_DIGITS)))
                self.assertTrue(any(any(colors[i]) for i in worker.flatten(d.USE_DIGITS)))

    def test_two_digit_layouts_never_clamp_100_to_99(self):
        for style in (LedStyle.CZ1, LedStyle.MAGIC_QUBE):
            for tick in (0, 1):
                self.assertFalse(any(any(c) for c in worker.colors_for(style, {'cpu_temp': 100, 'cpu_load': 100}, tick)))
                self.assertTrue(any(any(c) for c in worker.colors_for(style, {'cpu_temp': 99, 'cpu_load': 99}, tick)))

    def test_negative_temperature_is_not_falsely_shown_as_zero(self):
        d = worker.get_display(LedStyle.PA120)
        colors = worker.colors_for(LedStyle.PA120, {'cpu_temp': -2, 'cpu_load': 12})
        self.assertTrue(all(colors[i] == worker.BLACK for i in d.ZONE_LEDS[0]))
        self.assertTrue(any(any(colors[i]) for i in d.ZONE_LEDS[1]))

    def test_capabilities_follow_the_negotiated_layout_not_the_usb_id(self):
        for style in worker.SUPPORTED:
            caps = worker.capabilities_for(style)
            self.assertIs(type(caps['power']), bool)
            self.assertIs(type(caps['frequency']), bool)
            self.assertIs(type(caps['zones']), bool)
            self.assertEqual(caps['power'], style in (LedStyle.AK120, LedStyle.LF8, LedStyle.LF12, LedStyle.LF15))
            self.assertEqual(caps['frequency'], style in (LedStyle.LF8, LedStyle.LF12, LedStyle.LF15))
        self.assertTrue(worker.capabilities_for(LedStyle.LF12)['decoration'])
        self.assertFalse(worker.capabilities_for(LedStyle.PA120)['decoration'])

    def test_extended_metrics_and_timestamps_reject_non_finite_and_boolean_values(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'metrics.json'
            cases = [None, [], {'timestamp': True}, {'timestamp': float('nan')}]
            cases.extend({'timestamp': 100, key: value} for key in ('cpu_watts', 'cpu_mhz')
                         for value in (True, -1, float('nan'), float('inf'), '25'))
            for sample in cases:
                path.write_text(json.dumps(sample))
                with self.subTest(sample=sample), self.assertRaises(ValueError):
                    worker.read_sample(path, now=100)
            path.write_text(json.dumps({'timestamp': 100, 'cpu_watts': 0, 'cpu_mhz': None}))
            self.assertEqual(worker.read_sample(path, now=110)['cpu_watts'], 0)
            with self.assertRaises(ValueError):
                worker.read_sample(path, now=110.1)


class RgbTests(unittest.TestCase):
    SAMPLE = {'cpu_temp': 88, 'cpu_load': 88, 'cpu_watts': 88, 'cpu_mhz': 5888}

    def test_default_preserves_the_existing_cyan(self):
        colors = worker.colors_for(LedStyle.LF12, self.SAMPLE)
        self.assertEqual(set(colors), {worker.BLACK, (0, 180, 255)})

    def test_static_colour_and_brightness_apply_to_lit_segments_only(self):
        colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb={'color': '#ff8040', 'brightness': 50})
        self.assertEqual(set(colors), {worker.BLACK, (128, 64, 32)})
        self.assertFalse(any(any(c) for c in worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb={'brightness': 0})))

    def test_per_zone_colours_do_not_change_the_numbers(self):
        palette = dict(temperature='#ff0000', load='#00ff00', power='#0000ff',
                       frequency='#ffffff', decoration='#ff00ff')
        colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb={'mode': 'zones', 'zone_colors': palette})
        mask = worker.mask_for(LedStyle.LF12, self.SAMPLE)
        for zone, indices in worker.layout_zones(LedStyle.LF12).items():
            for i in indices:
                self.assertEqual(colors[i], worker.parse_color(palette[zone]) if mask[i] else worker.BLACK)

    def test_rotating_metric_zones_use_their_own_colour(self):
        cfg = {'mode': 'zones', 'zone_colors': {'temperature': '#ff0000', 'load': '#00ff00'}}
        for tick, expected in ((0, (255, 0, 0)), (1, (0, 255, 0))):
            colors = worker.colors_for(LedStyle.AX120, self.SAMPLE, tick, rgb=cfg)
            self.assertEqual(set(colors), {worker.BLACK, expected})

    def test_temperature_gradient_matches_all_customisable_stops(self):
        cfg = worker.rgb_settings()
        expected = {0: (0, 180, 255), 20: (0, 180, 255), 40: (0, 255, 0),
                    60: (255, 153, 0), 80: (255, 0, 0), 100: (255, 0, 0)}
        for temp, color in expected.items():
            self.assertEqual(worker.temperature_color(temp, cfg), color)
        self.assertEqual(worker.temperature_color(50, cfg), (127.5, 204, 0))
        cfg = worker.rgb_settings({'thresholds': [30, 50, 70],
                                  'temperature_colors': ['#000000', '#222222', '#444444', '#666666']})
        self.assertEqual(worker.temperature_color(40, cfg), (51, 51, 51))

    def test_temperature_smoothing_uses_elapsed_time_but_displays_current_digits(self):
        engine = worker.RgbEngine()
        cfg = {'mode': 'temperature', 'smoothing': 3}
        cold = dict(self.SAMPLE, cpu_temp=20)
        hot = dict(self.SAMPLE, cpu_temp=80)
        worker.colors_for(LedStyle.LF12, cold, now=10, engine=engine, rgb=cfg)
        colors = worker.colors_for(LedStyle.LF12, hot, now=13, engine=engine, rgb=cfg)
        alpha = 1 - math.exp(-1)
        expected = tuple(round(a + (b - a) * alpha) for a, b in zip((0, 180, 255), (255, 0, 0)))
        self.assertEqual({color for color in colors if any(color)}, {expected})
        self.assertEqual([any(c) for c in colors], worker.mask_for(LedStyle.LF12, hot))
        instant = worker.colors_for(LedStyle.LF12, hot, now=13, engine=engine, rgb=dict(cfg, smoothing=0))
        self.assertEqual({color for color in instant if any(color)}, {(255, 0, 0)})

    def test_missing_temperature_uses_fixed_colour_instead_of_a_cold_reading(self):
        engine = worker.RgbEngine()
        cfg = {'mode': 'temperature', 'color': '#aabbcc', 'smoothing': 30}
        worker.colors_for(LedStyle.LF12, dict(self.SAMPLE, cpu_temp=90), now=1, engine=engine, rgb=cfg)
        sample = dict(self.SAMPLE, cpu_temp=None)
        colors = worker.colors_for(LedStyle.LF12, sample, now=2, engine=engine, rgb=cfg)
        self.assertEqual({color for color in colors if any(color)}, {(170, 187, 204)})
        self.assertIsNone(engine.temperature)
        d = worker.get_display(LedStyle.LF12)
        self.assertTrue(all(colors[i] == worker.BLACK for i in worker.flatten(d.TEMP_DIGITS)))

    def test_effect_target_limits_colour_changes_to_selected_groups(self):
        d = worker.get_display(LedStyle.LF12)
        for target in ('digits', 'decoration'):
            cfg = {'mode': 'temperature', 'color': '#aabbcc', 'target': target, 'smoothing': 0}
            colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb=cfg)
            mask = worker.mask_for(LedStyle.LF12, self.SAMPLE)
            for i, on in enumerate(mask):
                affected = (i in d.DECORATION) == (target == 'decoration')
                self.assertEqual(colors[i], ((255, 0, 0) if affected else (0, 180, 255)) if on else worker.BLACK)

    def test_legacy_temperature_configuration_selects_every_category(self):
        cfg = worker.rgb_settings({'mode': 'temperature'})
        self.assertEqual(cfg['temperature_zones'], list(worker.ZONE_NAMES))
        colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb=cfg)
        self.assertEqual({color for color in colors if any(color)}, {(255, 0, 0)})

    def test_lf12_temperature_digits_alone_follow_heat_while_other_categories_stay_fixed(self):
        palette = dict(temperature='#010203', load='#123456', power='#654321',
                       frequency='#c0ffee', decoration='#789abc')
        sample = dict(self.SAMPLE, cpu_temp=42)
        cfg = {'mode': 'temperature', 'temperature_zones': ['temperature'],
               'zone_colors': palette, 'smoothing': 0, 'brightness': 50}
        colors = worker.colors_for(LedStyle.LF12, sample, rgb=cfg)
        mask = worker.mask_for(LedStyle.LF12, sample)
        heat = worker.temperature_color(42, worker.rgb_settings(cfg))
        for zone, indices in worker.layout_zones(LedStyle.LF12).items():
            expected = heat if zone == 'temperature' else worker.parse_color(palette[zone])
            expected = tuple(round(value * 0.5) for value in expected)
            for index in indices:
                self.assertEqual(colors[index], expected if mask[index] else worker.BLACK, (zone, index))
        self.assertEqual([any(color) for color in colors], mask)

    def test_temperature_categories_and_global_target_form_an_intersection(self):
        palette = {zone: '#123456' for zone in worker.ZONE_NAMES}
        for target, selected, dynamic in [('decoration', ['temperature'], []),
                                          ('digits', ['temperature', 'decoration'], ['temperature']),
                                          ('all', ['power', 'frequency'], ['power', 'frequency'])]:
            cfg = {'mode': 'temperature', 'target': target, 'temperature_zones': selected,
                   'zone_colors': palette, 'smoothing': 0}
            colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb=cfg)
            mask = worker.mask_for(LedStyle.LF12, self.SAMPLE)
            with self.subTest(target=target, selected=selected):
                for zone, indices in worker.layout_zones(LedStyle.LF12).items():
                    expected = (255, 0, 0) if zone in dynamic else (18, 52, 86)
                    for index in indices:
                        self.assertEqual(colors[index], expected if mask[index] else worker.BLACK, (zone, index))

    def test_missing_temperature_uses_fallback_only_for_selected_categories(self):
        sample = dict(self.SAMPLE, cpu_temp=None)
        palette = {zone: '#123456' for zone in worker.ZONE_NAMES}
        cfg = {'mode': 'temperature', 'temperature_zones': ['power'], 'color': '#aabbcc',
               'zone_colors': palette}
        colors = worker.colors_for(LedStyle.LF12, sample, rgb=cfg)
        d = worker.get_display(LedStyle.LF12)
        self.assertEqual(colors[d.WATT], (170, 187, 204))
        self.assertEqual(colors[d.MHZ], (18, 52, 86))
        self.assertTrue(all(colors[i] == worker.BLACK for i in worker.flatten(d.TEMP_DIGITS)))

    def test_rotating_display_keeps_unselected_load_category_fixed(self):
        cfg = {'mode': 'temperature', 'temperature_zones': ['temperature'],
               'zone_colors': {'load': '#123456'}}
        temperature = worker.colors_for(LedStyle.AX120, self.SAMPLE, tick=0, rgb=cfg)
        load = worker.colors_for(LedStyle.AX120, self.SAMPLE, tick=1, rgb=cfg)
        self.assertEqual({color for color in temperature if any(color)}, {(255, 0, 0)})
        self.assertEqual({color for color in load if any(color)}, {(18, 52, 86)})

    def test_temperature_category_selection_does_not_change_other_rgb_modes(self):
        for mode in ('static', 'zones', 'breathing', 'rainbow'):
            cfg = {'mode': mode, 'temperature_zones': ['temperature']}
            self.assertEqual(worker.colors_for(LedStyle.LF12, self.SAMPLE, now=1, rgb=cfg),
                             worker.colors_for(LedStyle.LF12, self.SAMPLE, now=1, rgb={'mode': mode}))

    def test_breathing_is_time_based_independent_of_metric_interval(self):
        cfg = {'mode': 'breathing', 'period': 4, 'color': '#ffffff'}
        high = worker.colors_for(LedStyle.LF12, self.SAMPLE, now=1, rgb=cfg)
        low = worker.colors_for(LedStyle.LF12, self.SAMPLE, now=3, rgb=cfg)
        again = worker.colors_for(LedStyle.LF12, self.SAMPLE, now=5, rgb=cfg)
        self.assertEqual(high, again)
        self.assertNotEqual(high, low)
        self.assertEqual([any(c) for c in high], [any(c) for c in low])

    def test_rainbow_keeps_each_digit_one_colour_and_animates(self):
        d = worker.get_display(LedStyle.LF12)
        cfg = {'mode': 'rainbow', 'period': 6}
        colors = worker.colors_for(LedStyle.LF12, self.SAMPLE, now=1, rgb=cfg)
        for groups in (d.TEMP_DIGITS, d.WATT_DIGITS, d.MHZ_DIGITS, d.USE_DIGITS):
            for group in groups:
                self.assertLessEqual(len({colors[i] for i in group if any(colors[i])}), 1)
        self.assertGreater(len({colors[i] for i in d.DECORATION}), 1)
        self.assertNotEqual(colors, worker.colors_for(LedStyle.LF12, self.SAMPLE, now=2, rgb=cfg))
        self.assertEqual([any(c) for c in colors], worker.mask_for(LedStyle.LF12, self.SAMPLE))

    def test_invalid_rgb_cannot_reach_the_usb_colours(self):
        cases = [False, {'mode': 'unknown'}, {'color': '#+f0000'}, {'color': '#ff0000<script>'},
                 {'brightness': True}, {'brightness': 101}, {'period': 0}, {'period': 61},
                 {'smoothing': float('nan')}, {'smoothing': 31}, {'thresholds': [40, 40, 80]},
                 {'thresholds': [-1, 40, 80]}, {'thresholds': [40, 60, 126]},
                 {'temperature_colors': []}, {'zone_colors': []}, {'target': 'usb'}]
        cases.extend({'temperature_zones': selection} for selection in
                     ([], ['temperature', 'temperature'], ['unknown'], ['temperature', None],
                      'temperature', None, {'temperature': True},
                      ['temperature', 'load', 'power', 'frequency', 'decoration', 'temperature']))
        for cfg in cases:
            with self.subTest(cfg=cfg), self.assertRaises(ValueError):
                worker.colors_for(LedStyle.LF12, self.SAMPLE, rgb=cfg)


class DisplayTestTests(unittest.TestCase):
    def test_rgb_and_all_segment_phases_expire_without_another_feed_write(self):
        visual = worker.DisplayTest()
        descriptor = {'id': 'test-1', 'started_at': 100, 'duration': 8}
        for age, phase, color in ((0, 'rouge', (255, 0, 0)), (2, 'vert', (0, 255, 0)),
                                  (4, 'bleu', (0, 0, 255)), (6, 'segments', (255, 255, 255))):
            frame, status = visual.frame(descriptor, 124, 100 + age, 200 + age)
            self.assertEqual(frame, [color] * 124)
            self.assertEqual(status['phase'], phase)
            self.assertTrue(status['active'])
        frame, status = visual.frame(descriptor, 124, 108, 208)
        self.assertIsNone(frame)
        self.assertFalse(status['active'])

    def test_monotonic_deadline_prevents_clock_rollback_from_prolonging_test(self):
        visual = worker.DisplayTest()
        descriptor = {'id': 'test-1', 'started_at': 100, 'duration': 8}
        visual.frame(descriptor, 124, 102, 200)
        frame, status = visual.frame(descriptor, 124, 101, 206)
        self.assertIsNone(frame)
        self.assertFalse(status['active'])
        frame, _ = visual.frame(descriptor, 124, 102, 207)
        self.assertIsNone(frame)

    def test_invalid_test_and_missing_test_never_replace_measurements(self):
        for descriptor in (None, {}, {'id': 'x', 'started_at': 101},
                           {'id': 'x', 'started_at': 90}, {'id': 'x', 'started_at': True},
                           {'id': 'x', 'started_at': 100, 'duration': float('inf')}):
            frame, status = worker.DisplayTest().frame(descriptor, 93, 100, 200)
            self.assertIsNone(frame)
            self.assertFalse(status['active'])

    def test_test_duration_is_hard_limited_to_fifteen_seconds(self):
        visual = worker.DisplayTest()
        descriptor = {'id': 'test-1', 'started_at': 100, 'duration': 600}
        _, status = visual.frame(descriptor, 93, 100, 200)
        self.assertEqual(status['remaining'], 15)
        frame, status = visual.frame(descriptor, 93, 115, 215)
        self.assertIsNone(frame)
        self.assertFalse(status['active'])

    def test_main_keeps_actual_measurements_during_test_and_restores_live_rgb(self):
        device = Mock()
        device.led_handshake.style = LedStyle.LF12
        device.led_handshake.model_name = 'test'
        stopper = Mock()
        stopper.is_set.side_effect = [False, False, True]
        sample = {'cpu_temp': 54, 'cpu_load': 21, 'cpu_watts': None, 'cpu_mhz': 5500,
                  'rgb': {'color': '#abcdef'}}
        during_test = dict(sample, display_test={'id': 'visual', 'started_at': 100, 'duration': 8})
        with patch.object(worker, 'Led', return_value=device), \
             patch.object(worker, 'STOP', stopper), \
             patch.object(worker.signal, 'signal'), \
             patch.object(worker, 'RotatingFileHandler', return_value=logging.NullHandler()), \
             patch.object(worker, 'read_sample', side_effect=[during_test, sample]), \
             patch.object(worker.time, 'time', return_value=100), \
             patch.object(worker.time, 'monotonic', return_value=200), \
             patch.object(worker, 'write_status') as status:
            self.assertEqual(worker.main(), 0)
        statuses = [call.kwargs for call in status.call_args_list]
        self.assertTrue(statuses[0]['test_active'])
        self.assertFalse(statuses[1]['test_active'])
        self.assertIsNone(statuses[0]['cpu_watts'])
        self.assertEqual(statuses[0]['cpu_mhz'], 5500)
        self.assertTrue(statuses[0]['capabilities']['frequency'])
        frames = [call.args[0] for call in device.send.call_args_list]
        self.assertEqual(frames[0].colors, [(255, 0, 0)] * 124)
        self.assertEqual(frames[1].colors, worker.colors_for(LedStyle.LF12, sample, now=200))
        self.assertFalse(frames[2].global_on)
        for call in stopper.wait.call_args_list:
            self.assertLessEqual(call.args[0], 0.1)

    def test_stale_feed_clears_all_leds_and_reports_error(self):
        device = Mock()
        device.led_handshake.style = LedStyle.LF12
        device.led_handshake.model_name = 'test'
        stopper = Mock()
        stopper.is_set.side_effect = [False, True]
        with patch.object(worker, 'Led', return_value=device), \
             patch.object(worker, 'STOP', stopper), \
             patch.object(worker.signal, 'signal'), \
             patch.object(worker, 'RotatingFileHandler', return_value=logging.NullHandler()), \
             patch.object(worker, 'read_sample', side_effect=ValueError('Mesures hôte périmées')), \
             patch.object(worker, 'write_status') as status:
            self.assertEqual(worker.main(), 0)
        self.assertEqual(device.send.call_args_list[0].args[0].colors, [worker.BLACK] * 124)
        self.assertIsNone(status.call_args.kwargs['cpu_watts'])
        self.assertFalse(status.call_args.kwargs['test_active'])
        self.assertEqual(status.call_args.kwargs['error'], 'Mesures hôte périmées')

    def test_failed_usb_frame_still_attempts_all_off_and_disconnects(self):
        device = Mock()
        device.led_handshake.style = LedStyle.LF12
        device.led_handshake.model_name = 'test'
        device.send.side_effect = [False, True]
        stopper = Mock()
        stopper.is_set.return_value = False
        with patch.object(worker, 'Led', return_value=device), \
             patch.object(worker, 'STOP', stopper), \
             patch.object(worker.signal, 'signal'), \
             patch.object(worker, 'RotatingFileHandler', return_value=logging.NullHandler()), \
             patch.object(worker, 'read_sample', return_value={'cpu_temp': 54}), \
             patch.object(worker, 'write_status') as status:
            self.assertEqual(worker.main(), 1)
        self.assertFalse(status.call_args.kwargs['ready'])
        self.assertEqual(status.call_args.kwargs['error'], 'Échec de transmission USB')
        self.assertEqual(device.send.call_count, 2)
        self.assertFalse(device.send.call_args.args[0].global_on)
        device.disconnect.assert_called_once()


if __name__ == '__main__':
    unittest.main(verbosity=2)
