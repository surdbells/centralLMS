/**
 * Makes `data-bs-toggle="tab"` work.
 *
 * Bootstrap's JavaScript reaches the application inside `vendor.bundle.js`,
 * and that bundle registers the click handlers for some of its components and
 * not others: dropdowns open, collapses collapse, and tabs do nothing at all.
 * Four screens are built out of Bootstrap tabs — the media manager, course
 * insights, the dictionary and the course player — and on every one of them
 * the first panel was the only panel anybody could reach. The media manager's
 * version history and upload panels had never once been seen.
 *
 * This is Bootstrap's own data-api, registered here because the bundle did not
 * register it: find the toggle that was clicked, show what it points at. The
 * markup does not change, which is the point — the templates keep the classes
 * and attributes Bootstrap documents, and start behaving the way those
 * attributes say they do.
 */
export function enableBootstrapTabs(): void {
  document.addEventListener('click', (event: MouseEvent) => {
    const target = event.target;

    if (!(target instanceof Element)) {
      return;
    }

    const toggle = target.closest<HTMLElement>('[data-bs-toggle="tab"]');

    if (toggle === null || toggle.hasAttribute('disabled')) {
      return;
    }

    // A tab toggle is often an `<a href="#panel">`, and following that would
    // append a fragment to the URL and scroll the page.
    event.preventDefault();

    bootstrapTab()?.getOrCreateInstance(toggle).show();
  });
}

interface BootstrapTab {
  getOrCreateInstance(element: Element): { show(): void };
}

/**
 * Bootstrap's `Tab`, if the vendor bundle loaded.
 *
 * Absent in a unit test, where there is no vendor bundle and nothing to show.
 */
function bootstrapTab(): BootstrapTab | null {
  const global = window as unknown as { bootstrap?: { Tab?: BootstrapTab } };

  return global.bootstrap?.Tab ?? null;
}
