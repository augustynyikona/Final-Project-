import 'package:flutter/services.dart';
import 'package:audioplayers/audioplayers.dart';

class SoundService {
  static final AudioPlayer _player = AudioPlayer();

  /// Plays a distinctive pop-up chime sound and triggers haptic vibration
  static Future<void> playPopSound() async {
    try {
      // 1. Play custom harmonic pop chime from assets
      await _player.stop();
      await _player.play(AssetSource('sounds/pop_notification.wav'), mode: PlayerMode.lowLatency);
    } catch (_) {}

    try {
      // 2. Native system alert and haptic feedback
      await SystemSound.play(SystemSoundType.alert);
      await HapticFeedback.heavyImpact();
    } catch (_) {}
  }
}

