// live-training/pipes/live-training-pipes.ts

import { Pipe, PipeTransform } from '@angular/core';

/**
 * Filter pipe - filters an array based on a property value
 * Usage: array | filter:'property':'value'
 */
@Pipe({
  name: 'filter',
  standalone: true,
  pure: false
})
export class FilterPipe implements PipeTransform {
  transform(items: any[], property: string, value: any): any[] {
    if (!items || !property) {
      return items;
    }
    return items.filter(item => item[property] === value);
  }
}

/**
 * Callback pipe - filters an array using a callback function
 * Usage: array | callback:filterFunction
 */
@Pipe({
  name: 'callback',
  standalone: true,
  pure: false
})
export class CallbackPipe implements PipeTransform {
  transform(items: any[], callback: (item: any) => boolean): any[] {
    if (!items || !callback) {
      return items;
    }
    return items.filter(callback);
  }
}

/**
 * Count pipe - counts items matching a filter
 * Usage: array | count:'property':'value'
 */
@Pipe({
  name: 'count',
  standalone: true,
  pure: false
})
export class CountPipe implements PipeTransform {
  transform(items: any[], property?: string, value?: any): number {
    if (!items) {
      return 0;
    }
    if (!property) {
      return items.length;
    }
    return items.filter(item => item[property] === value).length;
  }
}

/**
 * Safe property access pipe
 * Usage: object | safe:'property.nested'
 */
@Pipe({
  name: 'safe',
  standalone: true
})
export class SafePipe implements PipeTransform {
  transform(value: any, path: string): any {
    if (!value || !path) {
      return null;
    }
    return path.split('.').reduce((obj, key) => obj?.[key], value);
  }
}

/**
 * Exported array of all pipes for module imports.
 * Note: Since pipes are standalone, they can be imported directly
 * where needed. This array is provided for convenience.
 *
 * @example
 * // In a standalone component:
 * imports: [FilterPipe, CallbackPipe]
 *
 * // Or import all at once:
 * imports: [...LIVE_TRAINING_PIPES]
 */
export const LIVE_TRAINING_PIPES = [
  FilterPipe,
  CallbackPipe,
  CountPipe,
  SafePipe
] as const;
