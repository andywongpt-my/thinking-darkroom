import { InputHTMLAttributes } from 'react';

export default function Checkbox({
    className = '',
    ...props
}: InputHTMLAttributes<HTMLInputElement>) {
    return (
        <input
            {...props}
            type="checkbox"
            className={
                'rounded border-zinc-700 bg-zinc-900/60 text-amber-400 shadow-sm focus:ring-amber-400/60 ' +
                className
            }
        />
    );
}
