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
                `inline-flex items-center rounded-md border border-transparent bg-rose-500 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-zinc-950 transition duration-150 ease-in-out hover:bg-rose-400 focus:outline-none focus:ring-2 focus:ring-rose-500/60 focus:ring-offset-2 focus:ring-offset-zinc-950 active:bg-rose-600 ${
                    disabled && 'opacity-25'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}
