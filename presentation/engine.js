const container = require('markdown-it-container');

// Containers: `::: cards`, `::: cards grid`, `::: note`, `::: columns`, `::: fragment`.
// The first word is the container name, every further word becomes an extra class.
const CONTAINERS = ['cards', 'card', 'note', 'columns', 'stats', 'fragment'];

function renderContainer(name) {
    return {
        render(tokens, idx) {
            const token = tokens[idx];
            if (token.nesting !== 1) return '</div>\n';
            const classes = token.info.trim().split(/\s+/).filter(Boolean);
            const attrs = name === 'fragment' ? ' data-marpit-fragment' : '';
            return `<div class="${classes.join(' ')}"${attrs}>\n`;
        },
    };
}

// Inside `cards` and `stats`, every h3 starts a new `.card` that runs until the next h3.
function wrapCards(state) {
    const out = [];
    const stack = []; // nesting level of each open container
    const cardOpen = [];
    let explicit = 0;
    const openCard = () => {
        const t = new state.Token('div_open', 'div', 1);
        t.attrs = [['class', 'card']];
        t.block = true;
        out.push(t);
        cardOpen[cardOpen.length - 1] = true;
    };
    const closeCard = () => {
        const t = new state.Token('div_close', 'div', -1);
        t.block = true;
        out.push(t);
        cardOpen[cardOpen.length - 1] = false;
    };
    for (const token of state.tokens) {
        if (token.type === 'container_cards_open' || token.type === 'container_stats_open') {
            out.push(token);
            stack.push(token.level);
            cardOpen.push(false);
            continue;
        }
        if (token.type === 'container_cards_close' || token.type === 'container_stats_close') {
            if (cardOpen[cardOpen.length - 1]) closeCard();
            stack.pop();
            cardOpen.pop();
            out.push(token);
            continue;
        }
        // an explicit `::: card` inside `::: cards` switches the automatic wrapping off
        if (token.type === 'container_card_open' && stack.length) {
            if (!explicit && cardOpen[cardOpen.length - 1]) closeCard();
            explicit++;
        }
        if (token.type === 'container_card_close' && stack.length) explicit--;
        if (stack.length && !explicit && token.type === 'heading_open' && token.tag === 'h3' && token.level === stack[stack.length - 1] + 1) {
            if (cardOpen[cardOpen.length - 1]) closeCard();
            openCard();
        }
        out.push(token);
    }
    state.tokens = out;
}

module.exports = ({ marp }) => {
    for (const name of CONTAINERS) marp.use(container, name, renderContainer(name));
    marp.markdown.core.ruler.push('sulu_cards', wrapCards);
    return marp;
};
