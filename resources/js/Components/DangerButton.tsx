import { ButtonHTMLAttributes } from 'react';

export default function DangerButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `td-press inline-flex items-center gap-2 rounded-lg border border-transparent bg-rose-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition duration-150 ease-in-out hover:bg-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-500/60 focus:ring-offset-2 focus:ring-offset-zinc-950 active:bg-rose-600 disabled:cursor-not-allowed disabled:opacity-25 ${disabled ? 'pointer-events-none' : ''} ` +
                className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}