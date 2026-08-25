// pipes/pipes.ts
import { Pipe, PipeTransform } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';

// ==========================================
// TIME FORMAT PIPE
// ==========================================
@Pipe({ name: 'timeFormat', standalone: true })
export class TimeFormatPipe implements PipeTransform {
  transform(seconds: number | null | undefined, format: 'short' | 'long' | 'clock' = 'short'): string {
    if (seconds === null || seconds === undefined || isNaN(seconds)) return '--';
    
    const absSeconds = Math.abs(Math.floor(seconds));
    const hours = Math.floor(absSeconds / 3600);
    const minutes = Math.floor((absSeconds % 3600) / 60);
    const secs = absSeconds % 60;
    const sign = seconds < 0 ? '-' : '';

    switch (format) {
      case 'clock':
        if (hours > 0) {
          return `${sign}${hours}:${this.pad(minutes)}:${this.pad(secs)}`;
        }
        return `${sign}${minutes}:${this.pad(secs)}`;
      
      case 'long':
        const parts: string[] = [];
        if (hours > 0) parts.push(`${hours} hour${hours !== 1 ? 's' : ''}`);
        if (minutes > 0) parts.push(`${minutes} minute${minutes !== 1 ? 's' : ''}`);
        if (secs > 0 || parts.length === 0) parts.push(`${secs} second${secs !== 1 ? 's' : ''}`);
        return sign + parts.join(', ');
      
      case 'short':
      default:
        if (hours > 0) return `${sign}${hours}h ${minutes}m`;
        if (minutes > 0) return `${sign}${minutes}m ${secs}s`;
        return `${sign}${secs}s`;
    }
  }

  private pad(num: number): string {
    return num.toString().padStart(2, '0');
  }
}


// ==========================================
// RELATIVE TIME PIPE
// ==========================================
@Pipe({ name: 'relativeTime', standalone: true })
export class RelativeTimePipe implements PipeTransform {
  transform(date: string | Date | null | undefined): string {
    if (!date) return '';
    
    const now = new Date();
    const then = new Date(date);
    const diffMs = now.getTime() - then.getTime();
    const diffSecs = Math.floor(diffMs / 1000);
    const diffMins = Math.floor(diffSecs / 60);
    const diffHours = Math.floor(diffMins / 60);
    const diffDays = Math.floor(diffHours / 24);
    const diffWeeks = Math.floor(diffDays / 7);
    const diffMonths = Math.floor(diffDays / 30);
    const diffYears = Math.floor(diffDays / 365);

    if (diffSecs < 60) return 'just now';
    if (diffMins < 60) return `${diffMins} minute${diffMins !== 1 ? 's' : ''} ago`;
    if (diffHours < 24) return `${diffHours} hour${diffHours !== 1 ? 's' : ''} ago`;
    if (diffDays < 7) return `${diffDays} day${diffDays !== 1 ? 's' : ''} ago`;
    if (diffWeeks < 4) return `${diffWeeks} week${diffWeeks !== 1 ? 's' : ''} ago`;
    if (diffMonths < 12) return `${diffMonths} month${diffMonths !== 1 ? 's' : ''} ago`;
    return `${diffYears} year${diffYears !== 1 ? 's' : ''} ago`;
  }
}


// ==========================================
// SCORE COLOR PIPE
// ==========================================
@Pipe({ name: 'scoreColor', standalone: true })
export class ScoreColorPipe implements PipeTransform {
  transform(score: number | null | undefined, passingScore: number = 70, type: 'class' | 'style' = 'class'): string {
    if (score === null || score === undefined) return '';

    let color: string;
    if (score >= passingScore) {
      color = score >= 90 ? 'success' : 'primary';
    } else if (score >= passingScore - 10) {
      color = 'warning';
    } else {
      color = 'danger';
    }

    if (type === 'style') {
      const colors: Record<string, string> = {
        success: '#198754',
        primary: '#0d6efd',
        warning: '#ffc107',
        danger: '#dc3545'
      };
      return colors[color];
    }

    return `text-${color}`;
  }
}


// ==========================================
// GRADE COLOR PIPE
// ==========================================
@Pipe({ name: 'gradeColor', standalone: true })
export class GradeColorPipe implements PipeTransform {
  transform(grade: string | null | undefined, type: 'class' | 'bg' = 'class'): string {
    if (!grade) return '';

    const letter = grade.toUpperCase().charAt(0);
    let color: string;

    switch (letter) {
      case 'A': color = 'success'; break;
      case 'B': color = 'info'; break;
      case 'C': color = 'primary'; break;
      case 'D': color = 'warning'; break;
      default: color = 'danger';
    }

    return type === 'bg' ? `bg-${color}` : `text-${color}`;
  }
}


// ==========================================
// SAFE HTML PIPE
// ==========================================
@Pipe({ name: 'safeHtml', standalone: true })
export class SafeHtmlPipe implements PipeTransform {
  constructor(private sanitizer: DomSanitizer) {}

  transform(html: string | null | undefined): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(html || '');
  }
}


// ==========================================
// TRUNCATE PIPE
// ==========================================
@Pipe({ name: 'truncate', standalone: true })
export class TruncatePipe implements PipeTransform {
  transform(value: string | null | undefined, limit: number = 50, ellipsis: string = '...'): string {
    if (!value) return '';
    if (value.length <= limit) return value;
    return value.substring(0, limit).trim() + ellipsis;
  }
}


// ==========================================
// FILE SIZE PIPE
// ==========================================
@Pipe({ name: 'fileSize', standalone: true })
export class FileSizePipe implements PipeTransform {
  transform(bytes: number | null | undefined, decimals: number = 2): string {
    if (bytes === null || bytes === undefined || bytes === 0) return '0 Bytes';

    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));

    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
  }
}


// ==========================================
// ORDINAL PIPE
// ==========================================
@Pipe({ name: 'ordinal', standalone: true })
export class OrdinalPipe implements PipeTransform {
  transform(value: number | null | undefined): string {
    if (value === null || value === undefined) return '';

    const suffixes = ['th', 'st', 'nd', 'rd'];
    const v = value % 100;
    return value + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
  }
}


// ==========================================
// PERCENTAGE PIPE
// ==========================================
@Pipe({ name: 'percentage', standalone: true })
export class PercentagePipe implements PipeTransform {
  transform(value: number | null | undefined, decimals: number = 1): string {
    if (value === null || value === undefined) return 'N/A';
    return value.toFixed(decimals) + '%';
  }
}


// ==========================================
// HIGHLIGHT PIPE
// ==========================================
@Pipe({ name: 'highlight', standalone: true })
export class HighlightPipe implements PipeTransform {
  constructor(private sanitizer: DomSanitizer) {}

  transform(text: string | null | undefined, search: string): SafeHtml {
    if (!text || !search) return text || '';

    const regex = new RegExp(`(${this.escapeRegex(search)})`, 'gi');
    const highlighted = text.replace(regex, '<mark>$1</mark>');
    return this.sanitizer.bypassSecurityTrustHtml(highlighted);
  }

  private escapeRegex(str: string): string {
    return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }
}


// ==========================================
// INITIALS PIPE
// ==========================================
@Pipe({ name: 'initials', standalone: true })
export class InitialsPipe implements PipeTransform {
  transform(name: string | null | undefined, maxChars: number = 2): string {
    if (!name) return '';

    const words = name.trim().split(/\s+/);
    if (words.length === 1) {
      return words[0].substring(0, maxChars).toUpperCase();
    }

    return words
      .slice(0, maxChars)
      .map(word => word.charAt(0))
      .join('')
      .toUpperCase();
  }
}


// ==========================================
// PLURAL PIPE
// ==========================================
@Pipe({ name: 'plural', standalone: true })
export class PluralPipe implements PipeTransform {
  transform(count: number, singular: string, plural?: string): string {
    if (count === 1) return `${count} ${singular}`;
    return `${count} ${plural || singular + 's'}`;
  }
}
