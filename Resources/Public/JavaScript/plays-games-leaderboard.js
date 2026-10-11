(() => {
    'use strict';

    function showBoard(wrapper, scoreKey) {
        if (
            !wrapper
            || wrapper.dataset.pgamesLeaderboardMode !== 'auto'
            || !scoreKey
        ) {
            return false;
        }

        const boards = Array.from(
            wrapper.querySelectorAll(
                '.pgames-leaderboard-board[data-pgames-score-key]'
            )
        );

        const matchingBoard = boards.find(
            board => board.dataset.pgamesScoreKey === scoreKey
        );

        if (!matchingBoard) {
            return false;
        }

        const noScore =
            wrapper.querySelector(
                '.pgames-leaderboard-no-score'
            );

        if (noScore) {
            noScore.hidden = true;
            noScore.classList.remove('is-active');
        }

        boards.forEach(board => {
            const active =
                board.dataset.pgamesScoreKey === scoreKey;

            board.hidden = !active;
            board.classList.toggle(
                'is-active',
                active
            );
        });

        return true;
    }

    function showNoScore(wrapper, gameName) {
        if (
            !wrapper
            || wrapper.dataset.pgamesLeaderboardMode !== 'auto'
        ) {
            return;
        }

        wrapper
            .querySelectorAll(
                '.pgames-leaderboard-board[data-pgames-score-key]'
            )
            .forEach(board => {
                board.hidden = true;
                board.classList.remove('is-active');
            });

        const noScore =
            wrapper.querySelector(
                '.pgames-leaderboard-no-score'
            );

        if (!noScore) {
            return;
        }

        const gameNameElement =
            noScore.querySelector(
                '.pgames-no-score-game-name'
            );

        if (gameNameElement) {
            gameNameElement.textContent =
                gameName || 'Game';
        }

        noScore.hidden = false;
        noScore.classList.add('is-active');
    }

    function initialise() {
        const activeScoreKey = String(
            window.__playsGamesTypo3ScoreRegistry?.activeScoreKey || ''
        );

        document
            .querySelectorAll(
                '.pgames-leaderboard-switcher[data-pgames-leaderboard-mode="auto"]'
            )
            .forEach(wrapper => {
                if (
                    activeScoreKey
                    && showBoard(wrapper, activeScoreKey)
                ) {
                    return;
                }

                const firstBoard =
                    wrapper.querySelector(
                        '.pgames-leaderboard-board'
                    );

                if (firstBoard) {
                    const scoreKey =
                        firstBoard.dataset.pgamesScoreKey;

                    if (scoreKey) {
                        showBoard(
                            wrapper,
                            scoreKey
                        );
                    }
                }
            });
    }

    document.addEventListener(
        'pgames:active-game',
        event => {
            const scoreKey =
                event.detail?.scoreKey
                    ? String(event.detail.scoreKey)
                    : '';

            const gameName =
                event.detail?.gameName
                    ? String(event.detail.gameName)
                    : '';

            const wrappers =
                document.querySelectorAll(
                    '.pgames-leaderboard-switcher[data-pgames-leaderboard-mode="auto"]'
                );

            if (!scoreKey) {
                wrappers.forEach(
                    wrapper => showNoScore(
                        wrapper,
                        gameName
                    )
                );
                return;
            }

            wrappers.forEach(
                wrapper => showBoard(
                    wrapper,
                    scoreKey
                )
            );
        }
    );

    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            initialise
        );
    } else {
        initialise();
    }
})();
