(() => {
    'use strict';

    const registry = window.__playsGamesTypo3ScoreRegistry || {
        frames: [],
        listening: false,
        tokenPromise: null,
        activeScoreKey: '',
    };

    window.__playsGamesTypo3ScoreRegistry = registry;

    const allowedOrigins = new Set([
        'https://plays-games.com',
        'https://www.plays-games.com',
    ]);

    function registerFrame(frame) {
        if (!frame || registry.frames.includes(frame)) {
            return;
        }

        registry.frames.push(frame);
    }

    function sourceIsKnown(source) {
        registry.frames = registry.frames.filter(
            (frame) => frame && frame.isConnected
        );

        return registry.frames.some(
            (frame) => frame.contentWindow === source
        );
    }

    function reply(source, targetOrigin, message) {
        try {
            source.postMessage(message, targetOrigin);
        } catch (error) {
            // The iframe may disappear before the acknowledgement arrives.
        }
    }

    async function getToken() {
        if (!registry.tokenPromise) {
            registry.tokenPromise = fetch(
                '/plays-games-widget/token',
                {
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                    },
                }
            )
                .then(async (response) => {
                    let result = {};

                    try {
                        result = await response.json();
                    } catch (error) {
                        result = {};
                    }

                    if (!response.ok || !result.ok) {
                        const failure = new Error(
                            result.error || 'token_failed'
                        );

                        failure.reason =
                            result.error || 'token_failed';

                        throw failure;
                    }

                    if (!result.request_token) {
                        throw new Error('missing_request_token');
                    }

                    return result.request_token;
                })
                .catch((error) => {
                    registry.tokenPromise = null;
                    throw error;
                });
        }

        return registry.tokenPromise;
    }

    async function saveScore(data) {
        let token = await getToken();

        let response = await fetch(
            '/plays-games-widget/score',
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-TYPO3-RequestToken': token,
                },
                body: JSON.stringify(data),
            }
        );

        let result = {};

        try {
            result = await response.json();
        } catch (error) {
            result = {};
        }

        /*
         * The Request Token may have expired while the page stayed open.
         * Fetch a fresh one and retry once.
         */
        if (
            response.status === 403 &&
            result.error === 'invalid_request_token'
        ) {
            registry.tokenPromise = null;
            token = await getToken();

            response = await fetch(
                '/plays-games-widget/score',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-TYPO3-RequestToken': token,
                    },
                    body: JSON.stringify(data),
                }
            );

            try {
                result = await response.json();
            } catch (error) {
                result = {};
            }
        }

        if (!response.ok || !result.ok) {
            const failure = new Error(
                result.error || 'score_save_failed'
            );

            failure.reason =
                result.error || 'score_save_failed';

            throw failure;
        }

        return result;
    }

    function ensureListener() {
        if (registry.listening) {
            return;
        }

        registry.listening = true;

        window.addEventListener('message', async (event) => {
            if (
                !allowedOrigins.has(event.origin) ||
                !sourceIsKnown(event.source)
            ) {
                return;
            }

            const data = event.data;

            if (!data || typeof data !== 'object') {
                return;
            }

            if (data.type === 'pgames.active_game') {
                const scoreKey = String(
                    data.score_key || ''
                );

                if (
                    scoreKey !== ''
                    && !/^[a-z0-9_-]+$/.test(scoreKey)
                ) {
                    return;
                }

                registry.activeScoreKey = scoreKey;

                document.dispatchEvent(
                    new CustomEvent(
                        'pgames:active-game',
                        {
                            detail: {
                                scoreKey,
                                gameId:
                                    Number.parseInt(
                                        data.game_id || 0,
                                        10
                                    ) || 0,
                                gameName: String(
                                    data.game_name || ''
                                ),
                            },
                        }
                    )
                );

                return;
            }

            if (data.type !== 'pgames.score') {
                return;
            }

            try {
                const result = await saveScore(data);

                reply(event.source, event.origin, {
                    type: 'pgames.score.ack',
                    score_key: String(
                        data.score_key || ''
                    ),
                    saved: true,
                    result,
                });

                document.dispatchEvent(
                    new CustomEvent(
                        'pgames:score-saved',
                        {
                            detail: result,
                        }
                    )
                );
            } catch (error) {
                const reason =
                    error.reason ||
                    error.message ||
                    'score_save_failed';

                if (reason === 'login_required') {
                    reply(event.source, event.origin, {
                        type: 'pgames.score.ack',
                        score_key: String(
                            data.score_key || ''
                        ),
                        saved: false,
                        reason: 'login_required',
                    });

                    return;
                }

                reply(event.source, event.origin, {
                    type: 'pgames.score.error',
                    score_key: String(
                        data.score_key || ''
                    ),
                    error: {
                        message: reason,
                    },
                });
            }
        });
    }

    function init() {
        document
            .querySelectorAll(
                '.plays-games-widget[data-pgames-score="1"] iframe'
            )
            .forEach(registerFrame);

        if (registry.frames.length) {
            ensureListener();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            init,
            { once: true }
        );
    } else {
        init();
    }
})();
