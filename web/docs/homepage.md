# RoseWatch homepage

The public `/` route introduces the workspace and its daily workflow. It exposes no
branch, camera, incident or ticket records. Guests enter through `/login`; active
signed-in users can open `/dashboard` or the daily checklist. Homepage responses
are private/no-store because shared Inertia props reflect the current session.
The brand on the login page and workspace sidebar links back to the homepage.

The implementation uses the existing Vue/Inertia stack and Rose's cream/burgundy
palette, Outfit text, Courgette headline accents, logo and brand waves. The page
stays in the established light theme. A framed editorial hero leads into a compact
benefits row, an interactive feature guide and a burgundy three-step daily routine.
Paragraphs and links retain the global 13px preference. Design settings: variance
6, motion 2, density 4; motion is limited to action feedback and disabled for
reduced-motion users. Action links have pill corners. No live counts, testimonials
or simulated operational records appear on the public page.

The feature guide explains daily camera checks, screenshots, incidents and external
tickets. Its accessible tabs support click, Enter/Space, arrow keys, Home and End,
with one selected tab and one visible panel. Each panel explains the actual steps
and relevant limits, including read-only historical dates and the distinction
between incidents and external tickets. Selecting a guide never writes app data.

## Images

- `public/brand/homepage-bakery.webp`: new generated hero image, optimized to WebP
  (1536 × 1024, approximately 211 KB).
- `public/brand/homepage-bakery-640.webp` and `homepage-bakery-960.webp`: responsive
  versions, approximately 51 KB and 91 KB. The homepage document preloads the
  matching image before the Vue page starts, using the same sizes/srcset.
- `public/brand/rose-bakeshop-mark.webp`: 128px version of the existing logo,
  approximately 5 KB, used in the homepage header and as the shared favicon.

The hero was created using the built-in image generation tool. It is illustrative
bakery imagery, not a photograph claimed to show a specific branch. Existing assets
were preserved. Final generation prompt:

> Use case: photorealistic-natural. Asset type: wide website homepage hero photograph for RoseWatch, an internal Rose Bakeshop CCTV operations app. Create a photorealistic, calm bakery interior at opening time, with fresh bread behind clean glass display counters, deep burgundy wood cabinetry, pale cream walls and warm natural morning light from side windows. A single small discreet dome CCTV camera mounted high near the ceiling, subtle rather than dominant, connects the scene to branch care and security. Editorial architectural photography, authentic natural textures, welcoming and orderly, believable bread shapes, no exaggerated glow. Wide landscape 3:2 composition suitable for cropping into a homepage photo panel, camera and cabinetry in the upper/right scene, avoid critical details at the edges. No people, no text, no typography, no logos, no badges, no UI, no watermarks. This is illustrative bakery imagery, not a claim to photograph an actual branch.

## Verification

`HomePageTest` uses guarded SQLite `:memory:` to cover guest access, authenticated
entry, inactive accounts, private fields and continued protection of workspace
routes. `node tmp/verify-home.cjs` checks the compiled public page, images, anchors,
login navigation, feature selection and keyboard navigation, paragraph size,
authenticated CTA fixtures, responsive layouts from 320px to 1440px and reduced
motion. All browser write requests are blocked.

The targeted homepage and authentication tests passed (22 tests, 421 assertions),
as did TypeScript checking, the production Vite build and the browser checks.
Lighthouse runs from an isolated temporary installation because the machine's npm
installation fails while loading `minipass-flush`. The redesigned page was audited
against the local Laravel development server with mobile throttling. After the
contrast and image-loading changes, the audit scored 74 for performance and 100
for accessibility, best practices and SEO. These are local diagnostic results,
not a production performance guarantee. Largest Contentful Paint was 4.6 seconds;
the local server does not compress the shared application JavaScript/CSS. No
production server configuration was changed for the homepage.

After deployment, rebuild Vite assets and refresh Laravel's route cache so `/`
uses the new controller instead of the former redirect. No schema migration,
seeding or authentication changes are required.
