import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import AppFileDropzone from './AppFileDropzone.vue';
import { i18n } from '@/shared/i18n';

function mountDropzone(props: Record<string, unknown> = {}) {
  return mount(AppFileDropzone, {
    props,
    global: {
      plugins: [i18n],
    },
  });
}

describe('AppFileDropzone', () => {
  it('renders interactive dropzone and emits selected files on file input change', async () => {
    const wrapper = mountDropzone({
      label: 'Homework upload',
      accept: '.pdf,.png',
    });

    const input = wrapper.find('input[type="file"]');
    expect(input.exists()).toBe(true);

    const file = new File(['hello'], 'homework.pdf', { type: 'application/pdf' });
    Object.defineProperty(input.element, 'files', { value: [file], configurable: true });
    await input.trigger('change');

    expect(wrapper.emitted('select')?.[0]?.[0]).toEqual([file]);
    expect(wrapper.emitted('update:modelValue')?.[0]?.[0]).toEqual(file);
  });

  it('supports drag-and-drop and clear action', async () => {
    const wrapper = mountDropzone();
    const zone = wrapper.find('.app-dropzone__zone');
    const file = new File(['abc'], 'notes.txt', { type: 'text/plain' });

    await zone.trigger('dragenter', { dataTransfer: { files: [file] } });
    expect(wrapper.classes()).toContain('app-dropzone--dragging');

    await zone.trigger('drop', { dataTransfer: { files: [file] } });
    expect(wrapper.classes()).not.toContain('app-dropzone--dragging');
    expect(wrapper.emitted('select')?.[0]?.[0]).toEqual([file]);

    await wrapper.setProps({ modelValue: file });
    expect(wrapper.find('.app-dropzone__file-name').text()).toBe('notes.txt');

    await wrapper.find('.app-dropzone__clear').trigger('click');
    expect(wrapper.emitted('clear')).toHaveLength(1);
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toBeNull();
  });

  it('shows progress bar and cancel button during upload', async () => {
    const wrapper = mountDropzone({
      busy: true,
      progress: 64,
      statusText: 'Uploading part 2',
      cancellable: true,
    });

    const bar = wrapper.find('[role="progressbar"]');
    expect(bar.attributes('aria-valuenow')).toBe('64');
    expect(wrapper.text()).toContain('64%');
    expect(wrapper.text()).toContain('Uploading part 2');

    await wrapper.find('.app-dropzone__cancel').trigger('click');
    expect(wrapper.emitted('cancel')).toHaveLength(1);
  });
});
