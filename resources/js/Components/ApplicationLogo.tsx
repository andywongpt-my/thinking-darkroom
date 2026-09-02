import type { SVGAttributes } from 'react';

type ApplicationLogoProps = SVGAttributes<SVGSVGElement> & {
    decorative?: boolean;
};

export default function ApplicationLogo({ decorative = false, ...props }: ApplicationLogoProps) {
    return (
        <svg
            {...props}
            data-testid="thinking-darkroom-logo"
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            role={decorative ? undefined : 'img'}
            aria-label={decorative ? undefined : 'Thinking Darkroom'}
            aria-hidden={decorative || undefined}
        >
            <path
                d="M20 9.5h24c5.799 0 10.5 4.701 10.5 10.5v24c0 5.799-4.701 10.5-10.5 10.5H20C14.201 54.5 9.5 49.799 9.5 44V20C9.5 14.201 14.201 9.5 20 9.5Z"
                fill="currentColor"
                opacity="0.14"
            />
            <path
                d="M20 9.5h24c5.799 0 10.5 4.701 10.5 10.5v24c0 5.799-4.701 10.5-10.5 10.5H20C14.201 54.5 9.5 49.799 9.5 44V20C9.5 14.201 14.201 9.5 20 9.5Z"
                fill="none"
                stroke="currentColor"
                strokeWidth="3"
            />
            <g fill="currentColor" opacity="0.45">
                <rect x="13.5" y="20" width="3.5" height="7" rx="1.25" />
                <rect x="13.5" y="37" width="3.5" height="7" rx="1.25" />
                <rect x="47" y="20" width="3.5" height="7" rx="1.25" />
                <rect x="47" y="37" width="3.5" height="7" rx="1.25" />
            </g>
            <path
                d="M32 15.5 42.8 21.75v12.5L32 40.5l-10.8-6.25v-12.5L32 15.5Z"
                fill="none"
                stroke="currentColor"
                strokeLinejoin="round"
                strokeWidth="3"
            />
            <path d="M24.5 24.5h15v4.2h-5.4v10.8h-4.2V28.7h-5.4v-4.2Z" fill="currentColor" />
            <circle data-part="safelight" cx="43.5" cy="20.5" r="3.5" fill="#fbbf24" />
        </svg>
    );
}
