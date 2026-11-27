import { beforeEach, describe, expect, it, vi } from 'vitest';
import { mediaController } from './mediaController';

describe('mediaController', () => {
  beforeEach(() => {
    mediaController.reset();
    window.localStorage.clear();
  });

  it('enforces mutual exclusion for exclusive instances and allows independent policy', async () => {
    const pauseA = vi.fn();
    const playA = vi.fn();
    const pauseB = vi.fn();
    const playB = vi.fn();
    const pauseInd = vi.fn();
    const playInd = vi.fn();

    const unregA = mediaController.registerInstance({
      id: 'a',
      policy: 'exclusive',
      getTrack: () => ({ id: 'a', kind: 'audio', src: '/a.mp3', title: 'Track A' }),
      play: playA,
      pause: pauseA,
    });
    const unregB = mediaController.registerInstance({
      id: 'b',
      policy: 'exclusive',
      getTrack: () => ({ id: 'b', kind: 'voice', src: '/b.webm', title: 'Voice B' }),
      play: playB,
      pause: pauseB,
    });
    const unregInd = mediaController.registerInstance({
      id: 'ind',
      policy: 'independent',
      getTrack: () => ({ id: 'ind', kind: 'audio', src: '/ind.mp3', title: 'Independent' }),
      play: playInd,
      pause: pauseInd,
    });

    await mediaController.play('a');
    expect(playA).toHaveBeenCalledTimes(1);
    expect(mediaController.state.activeId).toBe('a');
    expect(mediaController.state.playing).toBe(true);

    // Independent playback does not pause exclusive track 'a'
    await mediaController.play('ind');
    expect(playInd).toHaveBeenCalledTimes(1);
    expect(pauseA).not.toHaveBeenCalled();

    // Playing exclusive track 'b' pauses 'a' but not 'ind'
    await mediaController.play('b');
    expect(pauseA).toHaveBeenCalledTimes(1);
    expect(pauseInd).not.toHaveBeenCalled();
    expect(playB).toHaveBeenCalledTimes(1);
    expect(mediaController.state.activeId).toBe('b');

    unregA();
    unregB();
    unregInd();
  });

  it('pauses active exclusive playback when recording starts', async () => {
    const pause = vi.fn();
    const unreg = mediaController.registerInstance({
      id: 'voice-1',
      policy: 'exclusive',
      getTrack: () => ({ id: 'voice-1', kind: 'voice', src: '/v.webm' }),
      play: vi.fn(),
      pause,
    });

    await mediaController.play('voice-1');
    expect(mediaController.state.playing).toBe(true);

    mediaController.notifyRecordingStart();
    expect(pause).toHaveBeenCalledTimes(1);
    expect(mediaController.state.playing).toBe(false);
    unreg();
  });

  it('manages volume, mute, playbackRate, seek, and queue navigation', async () => {
    const seekA = vi.fn();
    const setVolA = vi.fn();
    const setMuteA = vi.fn();
    const setRateA = vi.fn();
    const playB = vi.fn();

    const unregA = mediaController.registerInstance({
      id: 'q1',
      policy: 'exclusive',
      getTrack: () => ({ id: 'q1', kind: 'audio', src: '/1.mp3' }),
      play: vi.fn(),
      pause: vi.fn(),
      seek: seekA,
      setVolume: setVolA,
      setMuted: setMuteA,
      setPlaybackRate: setRateA,
    });
    const unregB = mediaController.registerInstance({
      id: 'q2',
      policy: 'exclusive',
      getTrack: () => ({ id: 'q2', kind: 'audio', src: '/2.mp3' }),
      play: playB,
      pause: vi.fn(),
    });

    mediaController.setQueue([
      { id: 'q1', kind: 'audio', src: '/1.mp3' },
      { id: 'q2', kind: 'audio', src: '/2.mp3' },
    ]);

    await mediaController.play('q1');
    mediaController.reportState('q1', { duration: 100 });
    mediaController.seek(42);
    expect(seekA).toHaveBeenCalledWith(42);
    expect(mediaController.state.currentTime).toBe(42);

    mediaController.setVolume(0.6);
    expect(setVolA).toHaveBeenCalledWith(0.6);
    mediaController.toggleMute();
    expect(setMuteA).toHaveBeenCalledWith(true);
    mediaController.setPlaybackRate(1.5);
    expect(setRateA).toHaveBeenCalledWith(1.5);

    await mediaController.playNext();
    expect(playB).toHaveBeenCalledTimes(1);
    expect(mediaController.state.activeId).toBe('q2');

    unregA();
    unregB();
  });

  it('tracks and revokes blob object URLs safely', () => {
    const revokeSpy = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
    mediaController.trackObjectUrl('blob:http://localhost/abc');
    mediaController.trackObjectUrl('https://example.com/not-blob');
    mediaController.revokeAllObjectUrls();
    expect(revokeSpy).toHaveBeenCalledTimes(1);
    expect(revokeSpy).toHaveBeenCalledWith('blob:http://localhost/abc');
    revokeSpy.mockRestore();
  });
});
