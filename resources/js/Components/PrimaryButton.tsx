import { ButtonHTMLAttributes } from 'react';

export default function PrimaryButton({
    className = '',
    disabled,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement>) {
    return (
        <button
            {...props}
            className={
                `td-press inline-flex items-center gap-2 rounded-lg border border-transparent bg-amber-400 px-4 py-2 text-sm font-semibold text-zinc-950 transition duration-150 ease-in-out hover:bg-amber-300 focus:bg-amber-300 focus:outline-none focus:ring-2 focus:ring-amber-400/60 focus:ring-offset-2 focus:ring-offset-zinc-950 active:bg-amber-200 disabled:cursor-not-allowed disabled:opacity-25 ${
                    disabled && 'pointer-events-none'
                } ` + className
            }
            disabled={disabled}
        >
            {children}
        </button>
    );
}