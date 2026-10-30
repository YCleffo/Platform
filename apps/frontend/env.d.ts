/// <reference types="vite/client" />

// IDE fallback for tools that do not run the Vue language service.
// vue-tsc/Volar still resolves real SFCs and type-checks them; this only prevents
// "Cannot find module '*.vue'" (TS2307) in plain TypeScript language servers.
declare module '*.vue' {
  import type { DefineComponent } from 'vue';

  const component: DefineComponent;
  export default component;
}
