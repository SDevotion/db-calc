function isCardCompatibleWithSocket(card, socketNumber) {
    const cardSocket = card.card_type_socket_number;

    if (cardSocket === null || cardSocket === undefined || cardSocket === '') {
        return true;
    }

    return Number(cardSocket) === Number(socketNumber);
}

function getSocketStates() {
    const saved = getSavedActiveItem();

    if (!saved) {
        return [];
    }

    const socketsCount = Number(itemCardsState.socketsCount || saved.sockets || 0);
    const serverBySocket = new Map();

    (itemCardsState.sockets || []).forEach(socket => {
        serverBySocket.set(Number(socket.socket_number), socket);
    });

    const userCards = saved.user_cards && typeof saved.user_cards === 'object' ?
        saved.user_cards : {};

    const states = [];

    for (let socket = 1; socket <= socketsCount; socket++) {
        const server = serverBySocket.get(socket) || null;
        const locked = server ? Number(server.is_locked || 0) === 1 : false;
        const key = String(socket);
        const hasUser = Object.prototype.hasOwnProperty.call(userCards, key);
        const userValue = userCards[key];

        let card = null;

        if (locked) {
            card = server && server.card ?
                getFullCard(server.card.id, server.card) :
                null;
        } else if (hasUser) {
            if (userValue && typeof userValue === 'object') {
                card = getFullCard(userValue.id, userValue);
            } else if (userValue) {
                card = getFullCard(userValue, null);
            } else {
                card = null;
            }
        } else {
            card = server && server.card ?
                getFullCard(server.card.id, server.card) :
                null;
        }

        states.push({
            socket_number: socket,
            locked,
            card,
            hasUser,
            userValue,
            serverCard: server && server.card ? server.card : null,
        });
    }

    return states;
}

function ensureActiveSocket() {
    const states = getSocketStates();

    if (!states.length) {
        activeSocketNumber = null;
        return;
    }

    const currentValid = states.some(state => state.socket_number === activeSocketNumber);

    if (currentValid) {
        return;
    }

    const freeUnlocked = states.filter(state => !state.locked && !state.card);

    if (freeUnlocked.length) {
        activeSocketNumber = freeUnlocked[0].socket_number;
        return;
    }

    const occupiedUnlocked = states.filter(state => !state.locked && state.card);

    if (occupiedUnlocked.length) {
        activeSocketNumber = occupiedUnlocked[0].socket_number;
        return;
    }

    activeSocketNumber = states[0].socket_number;
}

function advanceActiveSocketFrom(previousSocket) {
    const states = getSocketStates();

    if (!states.length) {
        activeSocketNumber = null;
        return;
    }

    const freeUnlocked = states.filter(state =>
        !state.locked &&
        !state.card &&
        state.socket_number !== Number(previousSocket)
    );

    if (freeUnlocked.length) {
        activeSocketNumber = freeUnlocked[0].socket_number;
        return;
    }

    const occupiedUnlocked = states.filter(state => !state.locked && state.card);

    if (occupiedUnlocked.length) {
        activeSocketNumber = occupiedUnlocked[0].socket_number;
        return;
    }

    activeSocketNumber = states[0].socket_number;
}

function renderSockets() {
    if (!modalSockets) {
        return;
    }
    if (modalTab !== 'stamps' && stampColorField) {
        stampColorField.hidden = true;
    }
    if (modalTab === 'stamps') {
        renderStampPanel();
        return;
    }
    const saved = getSavedActiveItem();

    if (modalOverlay.hidden || modalTab !== 'cards' || !saved) {
        modalSockets.hidden = true;
        modalSockets.textContent = '';
        return;
    }

    modalSockets.textContent = '';
    modalSockets.hidden = false;

    if (itemCardsState.loading) {
        const loading = document.createElement('div');
        loading.className = 'socket-empty';
        loading.textContent = 'Загрузка слотов...';
        modalSockets.appendChild(loading);
        return;
    }

    const states = getSocketStates();

    if (!states.length) {
        const empty = document.createElement('div');
        empty.className = 'socket-empty';
        empty.textContent = 'У предмета нет слотов.';
        modalSockets.appendChild(empty);
        return;
    }

    states.forEach(state => {
        const chip = document.createElement('div');
        chip.className = 'socket-chip';
        chip.dataset.socket = String(state.socket_number);

        if (state.socket_number === activeSocketNumber) {
            chip.classList.add('active');
        }

        if (state.locked) {
            chip.classList.add('forced');
            chip.title = 'Принудительная карта. Замена невозможна.';
        } else {
            chip.title = state.card ?
                'Выбрать слот для замены карты' :
                'Выбрать слот для установки карты';
        }

        chip.tabIndex = 0;

        const main = document.createElement('div');
        main.className = 'socket-chip-main';

        if (state.card && state.card.icon_path) {
            const icon = document.createElement('img');
            icon.className = 'socket-icon';
            icon.src = state.card.icon_path;
            icon.alt = state.card.name || '';
            icon.addEventListener('error', () => icon.remove());
            main.appendChild(icon);
        }

        const text = document.createElement('div');
        text.className = 'socket-text';

        const title = document.createElement('span');
        title.className = 'socket-title';
        title.textContent = 'Слот #' + state.socket_number;

        const cardName = document.createElement('span');
        cardName.className = 'socket-card';
        cardName.textContent = state.card ?
            state.card.name :
            (state.locked ? 'нет карты' : 'Пусто');

        text.appendChild(title);
        text.appendChild(cardName);
        main.appendChild(text);
        chip.appendChild(main);

        chip.addEventListener('click', () => setSocketActive(state.socket_number));
        chip.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                setSocketActive(state.socket_number);
            }
        });

        if (!state.locked && state.card) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'socket-remove';
            remove.textContent = '×';
            remove.title = 'Снять карту';

            remove.addEventListener('click', event => {
                event.stopPropagation();
                removeSocketCard(state.socket_number);
            });

            chip.appendChild(remove);
        }

        modalSockets.appendChild(chip);
    });
}

function setSocketActive(socketNumber) {
    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(socketNumber));

    if (!state) {
        return;
    }

    activeSocketNumber = Number(socketNumber);
    renderSockets();
    renderCardsTab();
}

function removeSocketCard(socketNumber) {
    const saved = getSavedActiveItem();

    if (!saved) {
        return;
    }

    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(socketNumber));

    if (!state || state.locked || !state.card) {
        return;
    }

    if (!saved.user_cards || typeof saved.user_cards !== 'object') {
        saved.user_cards = {};
    }

    saved.user_cards[String(socketNumber)] = null;

    saveEquipment();
    ensureActiveSocket();
    renderSockets();
    renderCardsTab();
}

function selectCardForActiveSocket(card) {
    if (activeSocketNumber === null) {
        return;
    }

    const saved = getSavedActiveItem();

    if (!saved) {
        return;
    }

    const states = getSocketStates();
    const state = states.find(item => item.socket_number === Number(activeSocketNumber));

    if (!state || state.locked) {
        return;
    }

    if (state.card && Number(state.card.id) === Number(card.id)) {
        removeSocketCard(activeSocketNumber);
        return;
    }

    const wasEmpty = !state.card;

    if (!saved.user_cards || typeof saved.user_cards !== 'object') {
        saved.user_cards = {};
    }

    saved.user_cards[String(activeSocketNumber)] = makeCardSnapshot(card);

    saveEquipment();

    if (wasEmpty) {
        advanceActiveSocketFrom(activeSocketNumber);
    } else {
        ensureActiveSocket();
    }

    renderSockets();
    renderCardsTab();
}

