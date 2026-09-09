import type Model from 'flarum/common/Model';

/**
 * The `#` / `@` mention-format registry that flarum/mentions installs onto the
 * forum app. Declared here because mentions is an optional dependency, so its
 * own types are not on our include path.
 *
 * 🚨 This file is a MODULE (note the import above). Augmenting an existing
 * module only works from a module file; the ambient `ext:` declaration in
 * ext-modules.d.ts has the opposite requirement, hence the split.
 */
interface MentionFormatShim {
  trigger(): string;
  format(...args: any[]): string;
  extend(mentionable: new (...args: any[]) => any): void;
}

declare module 'flarum/forum/ForumApplication' {
  export default interface ForumApplication {
    mentionFormats: {
      get(symbol: string): MentionFormatShim | null;
      extend(format: new () => any): void;
      mentionable(type: string): unknown | null;
    };
  }
}
