export type WorkspaceNotification = { kind: 'ok' | 'err'; text: string };

export type NotificationTimerRef = {
    current: ReturnType<typeof setTimeout> | null;
};

/** Schedule the shared five-second notification timeout when the UI is idle. */
export function autoDismissNotification(
    notification: WorkspaceNotification | null,
    busy: string | null,
    setNotification: (value: WorkspaceNotification | null) => void,
    timerRef: NotificationTimerRef,
): () => void {
    const clearTimer = () => {
        if (timerRef.current !== null) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }
    };

    clearTimer();

    if (!notification || busy !== null) {
        return clearTimer;
    }

    timerRef.current = setTimeout(() => {
        timerRef.current = null;
        setNotification(null);
    }, 5_000);

    return clearTimer;
}
