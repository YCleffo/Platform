import { beforeEach, describe, it, expect, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { mediaController } from '@/shared/media/mediaController';
import MediaPlayer from './MediaPlayer.vue';
import AudioPlayer from './AudioPlayer.vue';
import VoiceMessagePlayer from './VoiceMessagePlayer.vue';
import VideoPlayer from './VideoPlayer.vue';
import ImageViewer from './ImageViewer.vue';
import DocumentPreview from './DocumentPreview.vue';
import AudioWaveform from './AudioWaveform.vue';
import VideoNotePlayer from './VideoNotePlayer.vue';
import VideoNoteRecorder from './VideoNoteRecorder.vue';
import FileAttachment from './FileAttachment.vue';
import type { MediaAsset } from './types';

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
  }),
}));

describe('Media UI Kit', () => {
  beforeEach(() => {
    mediaController.reset();
    mediaController.setPlaybackRate(1);
    mediaController.setVolume(1);
    window.localStorage.clear();
  });

  it('renders VoiceMessagePlayer for voice media kind', () => {
    const asset: MediaAsset = {
      id: 'ast-voice-1',
      kind: 'voice',
      originalName: 'voice_note.ogg',
      originalMime: 'audio/ogg',
      sizeBytes: 15400,
      sha256: 'a'.repeat(64),
      storageKey: 'stg-1',
      durationSeconds: 12.5,
      status: 'READY',
      playbackUrl: '/api/media/stream/ast-voice-1',
    };

    const wrapper = mount(MediaPlayer, {
      props: { asset },
    });

    expect(wrapper.findComponent(VoiceMessagePlayer).exists()).toBe(true);
    expect(wrapper.findComponent(AudioPlayer).exists()).toBe(true);
    expect(wrapper.text()).toContain('0:00 / 0:12');
  });

  it('renders VideoNotePlayer for video_note media kind', () => {
    const asset: MediaAsset = {
      id: 'ast-video-1',
      kind: 'video_note',
      originalName: 'circle.mp4',
      originalMime: 'video/mp4',
      sizeBytes: 124000,
      sha256: 'b'.repeat(64),
      storageKey: 'stg-2',
      durationSeconds: 8.0,
      status: 'READY',
      playbackUrl: '/api/media/stream/ast-video-1',
    };

    const wrapper = mount(MediaPlayer, {
      props: { asset },
    });

    expect(wrapper.findComponent(VideoNotePlayer).exists()).toBe(true);
    expect(wrapper.find('.app-video-note-player').exists()).toBe(true);
  });

  it('cycles playback speed on VideoNotePlayer', async () => {
    const wrapper = mount(VideoNotePlayer, {
      props: {
        src: '/test.mp4',
        autoplay: false,
      },
    });

    // Simulate play so speed button is visible
    await wrapper.trigger('click');
    const speedBtn = wrapper.find('.app-video-note-player__speed');
    expect(speedBtn.exists()).toBe(true);
    expect(speedBtn.text()).toBe('1x');

    await speedBtn.trigger('click');
    expect(speedBtn.text()).toBe('1.5x');

    await speedBtn.trigger('click');
    expect(speedBtn.text()).toBe('2x');
  });

  it('renders FileAttachment for document media kind', () => {
    const asset: MediaAsset = {
      id: 'ast-doc-1',
      kind: 'document',
      originalName: 'assignment_guide.pdf',
      originalMime: 'application/pdf',
      sizeBytes: 204800,
      sha256: 'c'.repeat(64),
      storageKey: 'stg-3',
      status: 'READY',
      playbackUrl: '/api/media/stream/ast-doc-1',
    };

    const wrapper = mount(MediaPlayer, {
      props: { asset },
    });

    expect(wrapper.findComponent(FileAttachment).exists()).toBe(true);
    expect(wrapper.text()).toContain('assignment_guide.pdf');
    expect(wrapper.text()).toContain('200.0 KB');
  });

  it('handles seeking in AudioWaveform correctly', async () => {
    const wrapper = mount(AudioWaveform, {
      props: {
        progress: 0.3,
        barCount: 20,
      },
    });

    await wrapper.trigger('keydown.right');
    expect(wrapper.emitted('seek')?.[0]).toEqual([0.35]);

    await wrapper.trigger('keydown.left');
    expect(wrapper.emitted('seek')?.[1]).toEqual([0.25]);

    await wrapper.trigger('keydown.home');
    expect(wrapper.emitted('seek')?.[2]).toEqual([0]);

    await wrapper.trigger('keydown.end');
    expect(wrapper.emitted('seek')?.[3]).toEqual([1]);
  });

  it('toggles playback speed in AudioPlayer', async () => {
    const wrapper = mount(AudioPlayer, {
      props: {
        src: '/test.mp3',
      },
    });

    const speedBtn = wrapper.find('.app-audio-player__speed-btn');
    expect(speedBtn.text()).toBe('1x');

    await speedBtn.trigger('click');
    expect(speedBtn.text()).toBe('1.5x');

    await speedBtn.trigger('click');
    expect(speedBtn.text()).toBe('2x');

    await speedBtn.trigger('click');
    expect(speedBtn.text()).toBe('1x');
  });

  it('renders VideoNoteRecorder and handles cancel', async () => {
    const wrapper = mount(VideoNoteRecorder, {
      props: { size: 240 },
    });
    expect(wrapper.find('.app-video-note-recorder__bubble').exists()).toBe(true);
    const cancelBtn = wrapper.find('.app-video-note-recorder__btn--cancel');
    expect(cancelBtn.exists()).toBe(true);
    await cancelBtn.trigger('click');
    expect(wrapper.emitted('cancel')).toBeTruthy();
  });

  it('renders VideoPlayer for video media kind with controls', () => {
    const wrapper = mount(MediaPlayer, {
      props: {
        kind: 'video',
        src: '/clip.mp4',
        name: 'Lesson Video',
      },
    });
    expect(wrapper.findComponent(VideoPlayer).exists()).toBe(true);
    expect(wrapper.find('video').exists()).toBe(true);
    expect(wrapper.find('.app-video-player__controls').exists()).toBe(true);
  });

  it('navigates and zooms in ImageViewer gallery', async () => {
    const wrapper = mount(ImageViewer, {
      props: {
        items: [
          { id: '1', src: '/img1.png', alt: 'First' },
          { id: '2', src: '/img2.png', alt: 'Second' },
        ],
      },
    });
    expect(wrapper.text()).toContain('1 / 2');
    expect(wrapper.find('.app-image-viewer__img').attributes('src')).toBe('/img1.png');

    await wrapper.find('.app-image-viewer__nav--next').trigger('click');
    expect(wrapper.text()).toContain('2 / 2');
    expect(wrapper.find('.app-image-viewer__img').attributes('src')).toBe('/img2.png');

    await wrapper.find('[aria-label="media.zoomIn"]').trigger('click');
    expect(wrapper.text()).toContain('125%');
  });

  it('renders PDF iframe and unsupported document fallback in DocumentPreview', () => {
    const pdfWrapper = mount(DocumentPreview, {
      props: {
        src: '/api/media/stream/pdf-1',
        name: 'syllabus.pdf',
        mime: 'application/pdf',
      },
    });
    expect(pdfWrapper.find('iframe.app-document-preview__pdf').exists()).toBe(true);

    const docxWrapper = mount(DocumentPreview, {
      props: {
        src: '/api/media/stream/doc-1',
        name: 'report.docx',
        mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      },
    });
    expect(docxWrapper.find('iframe').exists()).toBe(false);
    expect(docxWrapper.text()).toContain('media.unsupportedPreview');
    expect(docxWrapper.find('a[download="report.docx"]').exists()).toBe(true);
  });
});
