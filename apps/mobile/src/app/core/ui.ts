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
