# Capitalio reference parity

The owner's 16 November correction requires the actual complete Capitalio registry design, not the earlier interpretation. This is an implementation directive, not approval of the previous screenshots.

Source: `https://registry.watermelon.sh/r/capitalio-dashboard.json`, refetched successfully and identical to the original receipt; SHA256 `8ca7f46ec8b0dedd5708b36391aae9aa0f131ddd2eddc644bc76e31c0d5d97c5`. The source supplies twelve files, Base UI and Recharts2.15.4. The complete supplied screenshot is [capitalio-complete-dashboard.png](capitalio-complete-dashboard.png), SHA256 `19ad357c0dc591c6db391eca1031b4a2ca24b94bd1ae8b5d38e827121100fe57`.

The official Watermelon host [theme](https://raw.githubusercontent.com/WatermelonCorp/watermelon-platform/main/src/styles/theme.css) declares `Inter Variable` for its sans font. Match Inter through the existing bundled font pipeline. No separate `font-section` definition was found in the bounded official CSS lookup; do not invent a second font mapping. The original Capitalio stylesheet already supplies the inset card shadow, active sidebar gradient/mask, conic icon badges, dot grid and progress animation.

## Required source map

| Registry file | Required implementation |
| --- | --- |
| `dashboard-layout.tsx` | Actual sidebar/content frame, inset shadow, viewport/scroll structure and reference spacing |
| `dashboard.css` | Actual dark surfaces, blue primary, green/purple/orange chart colors, shadows, active navigation, metallic conic icon background and source effects |
| `components/capitalio/sidebar.tsx` | Actual sidebar sizing, grouping/collapse, active inset treatment, footer/account structure and mobile behavior; map Kernscale routes and authenticated identity |
| `components/capitalio/topbar.tsx` | Actual title/update/currency row, date range, all four period controls and working export |
| `components/capitalio/navigation.tsx` | Preserve reference interactions in preview and use actual Inertia navigation in the application |
| `components/capitalio/shared.tsx` | Actual cards, badges, tooltip, labels/markers and shared chart treatments |
| `components/capitalio/icons.tsx` | Actual icon artwork and effects; straight action/navigation arrows follow the owner's standing preference |
| `components/capitalio/logo.tsx` | Reference blue logo treatment; Kernscale identity in actual application |
| `components/capitalio/dashboard-page.tsx` | Every dashboard block listed below |
| `components/capitalio/analytics-page.tsx` | Every analytics block listed below |
| `data.ts` | Original complete fixtures restricted to a clearly labelled local-only design preview; actual views use verified application/native records |
| `demo.tsx` | Complete reference preview including dashboard/analytics and period changes; no blank/dead app navigation |

## Blocks that must remain visible

Dashboard: Total Net Worth/equity card with green sparkline/gradient; Portfolio Return card with progress/goal and blue bars; Asset Classes card with striped green/blue allocation; Risk Score card with orange area; Portfolio Performance chart with dotted background, comparison-line treatment, tooltip and lower class metrics; segmented Asset Allocation donut/legend; Top Holdings table with source row treatments; Smart Insights cards with their icons and effects.

Analytics: multi-series performance with legend and comparison treatments; all three metric cards/charts; class-return bars; dividend/rental income stacked chart. Preserve Monthly, Weekly, Quarterly and Yearly controls across the supplied reference datasets.

The R1 session list/chat/source/thesis/board features, outputs, community, portfolio/watchlists, membership and security remain operational inside the same Capitalio visual system. Replacing the functional app with only a standalone demo does not meet this requirement.

## Data and acceptance

The `/design/capitalio` route is restricted to an approved/enrolled local owner and returns404 outside local mode. It displays a persistent sample-data label and the complete original fixture visuals. The production app does not acquire the sample $2.8M balance,14.7% return,62/100 risk, invented benchmark, target or income. Native views keep every visual block and use actual metrics/provenance or a compact missing-data state where the required data/methodology is absent. Source chart geometry is not a license to fabricate financial history.

Acceptance requires actual browser screenshots of the full reference preview and both original dashboard/analytics surfaces, including lower blocks. Compare the main reference at approximately1497x823 logical pixels (the supplied image is2994x1646), then check desktop, tablet and mobile for clipping, navigation and chart interactions. Verify all four periods, tooltip, export, full-analysis navigation, real application navigation and retained R1/card editing. Check the live/native view separately for sample-value leakage. Preserve reduced motion, focus behavior and private session boundaries.

Record actual source-copy/adaptation differences, changed-file formatting/lint, affected frontend types, targeted route tests, screenshots and remaining parity gaps before claiming completion. No claim of exact parity follows from merely installing the registry or passing TypeScript.
