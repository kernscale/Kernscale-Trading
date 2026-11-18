# Kit sources

- **Composer + mention menu** — interaction model and layout reverse-engineered from AI SDK Agents “Mention menu” (<https://www.aisdkagents.com/ai-components/mention-menu>): grouped results while typing `@`, arrow keys with Enter/Tab to pick, an active-row `↵` hint and mentions that delete as one piece. No source code was copied; the implementation is original and uses only the existing textarea, lucide icons and CSS.
- **Micro interactions** — React Bits micro collection by David Haz (<https://reactbits.dev/c/micro>): Rubber Segment, Status Mark, Thought Line, Call Chip and Sling Button. The registry versions depend on `motion` and Hugeicons; these are dependency-free CSS adaptations of their behaviour (stretch-and-squash thumb, ring → arc → drawn check, shimmer label with live clock that collapses its steps, fill-then-settle tool chip, pull-back send button). License: MIT + Commons Clause, retained in `../hub/REACT-BITS-LICENSE.md`.

All motion is disabled under `prefers-reduced-motion`. No component fabricates data: empty states render `Quiet` rows instead of sample values.
