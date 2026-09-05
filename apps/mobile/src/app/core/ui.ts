/** Small presentation helpers shared across the learner screens. */

/** A stable hue from a string, for course-thumbnail placeholders. */
export function hueOf(text: string): number {
  let h = 0;
  for (let i = 0; i < text.length; i++) {
    h = (h * 31 + text.charCodeAt(i)) % 360;
  }
  return h;
}

export function thumbStyle(title: string): Record<string, string> {
  const h = hueOf(title || '?');
  return {
    background: `linear-gradient(135deg, hsl(${h} 60% 52%), hsl(${(h + 40) % 360} 62% 42%))`,
  };
}

export function initialOf(title: string): string {
  return (title || '?').trim().charAt(0).toUpperCase() || '?';
}

export function minutesLabel(minutes: number | null | undefined): string {
  const m = Math.round(minutes ?? 0);
  if (m <= 0) {
    return '';
  }
  if (m < 60) {
    return `${m} min`;
  }
  const h = Math.floor(m / 60);
  const rest = m % 60;
  return rest ? `${h}h ${rest}m` : `${h}h`;
}

export function firstName(fullName: string | null | undefined): string {
  return (fullName ?? '').trim().split(/\s+/)[0] || 'there';
}

/** "Just now", "3d ago", or a short date for anything older than a week. */
export function timeAgo(iso: string | null | undefined): string {
  if (!iso) {
    return '';
  }
  const then = new Date(iso).getTime();
  if (isNaN(then)) {
    return '';
  }
  const mins = Math.round((Date.now() - then) / 60000);
  if (mins < 1) {
    return 'Just now';
  }
  if (mins < 60) {
    return `${mins}m ago`;
  }
  const hours = Math.round(mins / 60);
  if (hours < 24) {
    return `${hours}h ago`;
  }
  const days = Math.round(hours / 24);
  if (days < 7) {
    return `${days}d ago`;
  }
  return new Date(then).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
