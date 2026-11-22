# Icons
Один линейный набор иконок на обе темы: сетка 24px, штрих 1,75px, прямые концы и углы (`linecap: square`, `linejoin: miter`) — в тон квадратным буквам логотипа.

**Что передаёт разработчик:** inline SVG `<svg class="pp-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">`. Размеры: `.pp-icon--s` 16, `.pp-icon` 20, `.pp-icon--l` 24. Исходники — группа ассетов «Иконки».

**Правила:** цвет — всегда `currentColor` (наследует `ink`, `on-accent` и т. д.). Иконка рядом с текстом — декоративная (`aria-hidden`); иконка без текста — только в кнопке с `aria-label`. Новые иконки рисуются по той же сетке; смешивать с другими наборами нельзя.
