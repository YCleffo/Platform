const interactive = [
  'a', 'button', 'input', 'textarea', 'select', 'label', 'summary', 'iframe', 'video',
  '[contenteditable]:not([contenteditable="false"])', '[draggable="true"]', '[data-no-page-swipe]',
  '[aria-roledescription="carousel"]', '.carousel', '.swiper',
  '[role="button"]', '[role="dialog"]', '[role="menu"]', '[role="listbox"]', '[role="slider"]',
  '[role="tab"]', '[role="combobox"]', '[role="scrollbar"]', '[role="spinbutton"]', 'dialog',
].join(', ');

const scrollable = /^(auto|scroll|overlay)$/;

export function startsOnPageSurface(target: EventTarget | null, page: Element): boolean {
  for (let element = target instanceof Element ? target : null; element; element = element.parentElement) {
    if (element === page) return true;
    if (element.matches(interactive)) return false;
    const style = getComputedStyle(element);
    if (
      (scrollable.test(style.overflowX) && element.scrollWidth > element.clientWidth) ||
      (scrollable.test(style.overflowY) && element.scrollHeight > element.clientHeight)
    ) return false;
  }
  return false;
}
