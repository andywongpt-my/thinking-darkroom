import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';
import { PropsWithChildren } from 'react';

export default function Guest({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col items-center bg-zinc-950 px-4 pt-8 sm:justify-center sm:pt-0">
            <div>
                <Link
                    href="/"
                    aria-label="Thinking Darkroom home"
                    className="group flex flex-col items-center rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-4 focus-visible:ring-offset-zinc-950"
                >
                    <ApplicationLogo decorative className="h-16 w-16 text-zinc-100 transition duration-200 group-hover:text-amber-300" />
                    <span className="mt-3 flex flex-col items-center">
                        <span className="text-sm font-semibold uppercase tracking-[0.16em] text-zinc-100">
                            Thinking <span className="text-amber-400">Darkroom</span>
                        </span>
                        <span className="mt-1 font-mono text-[9px] uppercase tracking-[0.24em] text-zinc-500">
                            Photographic judgment
                        </span>
                    </span>
                </Link>
            </div>

            <div className="mt-7 w-full overflow-hidden bg-zinc-900/60 px-6 py-4 shadow-md ring-1 ring-zinc-800 sm:max-w-md sm:rounded-lg">
                {children}
            </div>
        </div>
    );
}
