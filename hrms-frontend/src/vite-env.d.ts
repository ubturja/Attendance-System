/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Laravel API origin, e.g. `http://localhost:8000` or `http://localhost:8000/api`. */
  readonly VITE_API_BASE_URL?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
