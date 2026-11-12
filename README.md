# The Lens Front End

Vue 3 client for The Lens Publication, built with Vite and Inertia.js. Laravel application code, database code, and server routes are maintained in the separate backend repository.

## Build

```sh
npm install
npm run build
```

Vite writes browser assets and a manifest to `dist/`. The backend repository should serve the public assets in `public/`, load the entry listed by the Vite manifest, and provide the Inertia root element with its initial page data.

## Backend integration contract

- Resolve Inertia page names to the Vue components under `src/Pages/`.
- Share `auth.user` with `id`, `name`, `studentNumber`, `role`, and `avatarUrl`, plus `flash.success`, `flash.error`, and `flash.warning`.
- Preserve the existing route paths and methods used by the frontend forms and actions. Authenticated requests use the same-origin session and CSRF token.
- Serve the static files under `public/css/` and `public/images/` from the app root.

This repository contains the browser client and its static assets; the backend repository supplies the Inertia responses, authentication, data, and route handlers.
