/**
 * Ambient declaration for the flarum/mentions module we import behind the
 * `ext:` prefix.
 *
 * 🚨 This file must NOT contain a top-level import or export. An ambient
 * external module declaration (`declare module 'ext:…'`, for a module that
 * TypeScript cannot otherwise resolve) is only legal in a global script file.
 * Adding an import here turns the file into a module and the declaration
 * silently stops applying — which is why the augmentation in
 * forum-application.d.ts lives in its own file instead.
 */
declare module 'ext:flarum/mentions/forum/mentionables/MentionableModel' {
  import type Model from 'flarum/common/Model';
  import type Mithril from 'mithril';

  export default abstract class MentionableModel<M extends Model = Model, F = any> {
    public format: F;
    public constructor(format: F);
    abstract type(): string;
    abstract initialResults(): M[];
    abstract search(typed: string): Promise<M[]>;
    abstract replacement(model: M): string;
    abstract suggestion(model: M, typed: string): Mithril.Children;
    abstract matches(model: M, typed: string): boolean;
    abstract maxStoreMatchedResults(): number | null;
    abstract enabled(): boolean;
  }
}
