import { Transition } from '@headlessui/react';
import { InertiaLinkProps, Link } from '@inertiajs/react';
import {
    Children,
    cloneElement,
    createContext,
    Dispatch,
    isValidElement,
    PropsWithChildren,
    SetStateAction,
    useCallback,
    useContext,
    useEffect,
    useId,
    useRef,
    useState,
} from 'react';
import type {
    HTMLAttributes,
    KeyboardEvent as ReactKeyboardEvent,
    MutableRefObject,
    MouseEvent as ReactMouseEvent,
    Ref,
    RefAttributes,
    RefCallback,
} from 'react';

type DropdownTriggerProps = HTMLAttributes<HTMLElement> & RefAttributes<HTMLElement> & {
    'data-dropdown-trigger'?: string;
    'data-state'?: string;
};

type DropdownMenuItemProps = {
    className?: string;
    role?: string;
};

type DropdownContextValue = {
    open: boolean;
    setOpen: Dispatch<SetStateAction<boolean>>;
    toggleOpen: () => void;
    closeDropdown: (restoreFocus?: boolean) => void;
    triggerId: string;
    menuId: string;
    setTriggerNode: (node: HTMLElement | null) => void;
};

const DropDownContext = createContext<DropdownContextValue>({
    open: false,
    setOpen: () => {},
    toggleOpen: () => {},
    closeDropdown: () => {},
    triggerId: '',
    menuId: '',
    setTriggerNode: () => {},
});

export const isDropdownEscapeKey = (key: string): boolean => key === 'Escape';

export function isOutsideDropdown(
    container: Pick<HTMLElement, 'contains'> | null,
    target: EventTarget | null,
): boolean {
    if (!container || target === null) {
        return false;
    }

    return !container.contains(target as Node);
}

export function mergeDropdownRefs<T>(...refs: Array<Ref<T> | undefined>): RefCallback<T> {
    return (value: T | null) => {
        refs.forEach((ref) => {
            if (typeof ref === 'function') {
                ref(value);
            } else if (ref) {
                (ref as MutableRefObject<T | null>).current = value;
            }
        });
    };
}

const Dropdown = ({ children }: PropsWithChildren) => {
    const [open, setOpen] = useState(false);
    const dropdownRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLElement | null>(null);
    const instanceId = useId();
    const triggerId = `dropdown-trigger-${instanceId}`;
    const menuId = `dropdown-menu-${instanceId}`;

    const setTriggerNode = useCallback((node: HTMLElement | null) => {
        triggerRef.current = node;
    }, []);

    const closeDropdown = useCallback((restoreFocus = false) => {
        setOpen(false);
        if (restoreFocus) {
            triggerRef.current?.focus();
        }
    }, []);

    const toggleOpen = useCallback(() => {
        setOpen((previousState) => !previousState);
    }, []);

    useEffect(() => {
        if (!open) {
            return undefined;
        }

        const handlePointerDown = (event: PointerEvent) => {
            if (isOutsideDropdown(dropdownRef.current, event.target)) {
                closeDropdown();
            }
        };
        const handleKeyDown = (event: globalThis.KeyboardEvent) => {
            if (isDropdownEscapeKey(event.key)) {
                event.preventDefault();
                closeDropdown(true);
            }
        };

        document.addEventListener('pointerdown', handlePointerDown);
        document.addEventListener('keydown', handleKeyDown);

        return () => {
            document.removeEventListener('pointerdown', handlePointerDown);
            document.removeEventListener('keydown', handleKeyDown);
        };
    }, [closeDropdown, open]);

    return (
        <DropDownContext.Provider
            value={{
                open,
                setOpen,
                toggleOpen,
                closeDropdown,
                triggerId,
                menuId,
                setTriggerNode,
            }}
        >
            <div ref={dropdownRef} className="relative">
                {children}
            </div>
        </DropDownContext.Provider>
    );
};

const Trigger = ({ children }: PropsWithChildren) => {
    const {
        open,
        toggleOpen,
        closeDropdown,
        triggerId,
        menuId,
        setTriggerNode,
    } = useContext(DropDownContext);
    const child = Children.toArray(children)[0];
    const triggerClasses = [
        'transition-colors duration-150',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400',
        'focus-visible:ring-offset-2 focus-visible:ring-offset-zinc-950',
        'data-[state=open]:bg-zinc-800 data-[state=open]:text-zinc-100',
    ].join(' ');

    if (isValidElement<DropdownTriggerProps>(child)) {
        const childOnClick = child.props.onClick;
        const childOnKeyDown = child.props.onKeyDown;
        const childClassName = child.props.className ?? '';
        const childRef = (child as typeof child & { ref?: Ref<HTMLElement> }).ref;

        return cloneElement(child, {
            id: child.props.id ?? triggerId,
            'aria-haspopup': 'menu',
            'aria-expanded': open,
            'aria-controls': menuId,
            'data-dropdown-trigger': 'true',
            'data-state': open ? 'open' : 'closed',
            ref: mergeDropdownRefs(childRef, setTriggerNode),
            onClick: (event: ReactMouseEvent<HTMLElement>) => {
                childOnClick?.(event);
                if (!event.defaultPrevented) {
                    toggleOpen();
                }
            },
            onKeyDown: (event: ReactKeyboardEvent<HTMLElement>) => {
                childOnKeyDown?.(event);
                if (isDropdownEscapeKey(event.key)) {
                    event.preventDefault();
                    closeDropdown(true);
                }
            },
            className: `${childClassName} ${triggerClasses}`.trim(),
        });
    }

    return (
        <button
            type="button"
            id={triggerId}
            aria-haspopup="menu"
            aria-expanded={open}
            aria-controls={menuId}
            data-dropdown-trigger="true"
            data-state={open ? 'open' : 'closed'}
            ref={setTriggerNode}
            onClick={toggleOpen}
            onKeyDown={(event) => {
                if (isDropdownEscapeKey(event.key)) {
                    event.preventDefault();
                    closeDropdown(true);
                }
            }}
            className={triggerClasses}
        >
            {children}
        </button>
    );
};

const Content = ({
    align = 'right',
    width = '48',
    contentClasses = 'py-1',
    children,
}: PropsWithChildren<{
    align?: 'left' | 'right';
    width?: '48';
    contentClasses?: string;
}>) => {
    const { open, setOpen, triggerId, menuId } = useContext(DropDownContext);

    let alignmentClasses = 'origin-top';

    if (align === 'left') {
        alignmentClasses = 'ltr:origin-top-left rtl:origin-top-right start-0';
    } else if (align === 'right') {
        alignmentClasses = 'ltr:origin-top-right rtl:origin-top-left end-0';
    }

    let widthClasses = '';

    if (width === '48') {
        widthClasses = 'w-48';
    }

    const menuChildren = Children.map(children, (child) => {
        if (!isValidElement<DropdownMenuItemProps>(child)) {
            return child;
        }

        const childClassName = child.props.className ?? '';
        return cloneElement(child, {
            role: child.props.role ?? 'menuitem',
            className: `${childClassName} min-h-11 flex items-center`.trim(),
        });
    });

    return (
        <Transition
            show={open}
            enter="transition ease-out duration-150"
            enterFrom="opacity-0 scale-95"
            enterTo="opacity-100 scale-100"
            leave="transition ease-in duration-100"
            leaveFrom="opacity-100 scale-100"
            leaveTo="opacity-0 scale-95"
        >
            <div
                id={menuId}
                role="menu"
                aria-labelledby={triggerId}
                aria-orientation="vertical"
                className={`absolute z-50 mt-2 min-w-48 overflow-hidden rounded-lg bg-zinc-900 shadow-xl shadow-zinc-950/20 ring-1 ring-zinc-600/80 ${alignmentClasses} ${widthClasses}`}
                onClick={() => setOpen(false)}
            >
                <div className={contentClasses}>{menuChildren}</div>
            </div>
        </Transition>
    );
};

const DropdownLink = ({
    className = '',
    children,
    ...props
}: InertiaLinkProps) => {
    return (
        <Link
            {...props}
            role="menuitem"
            className={
                'flex min-h-11 w-full items-center px-4 py-3 text-start text-sm leading-5 text-zinc-300 transition-colors duration-150 ease-in-out hover:bg-zinc-800 hover:text-zinc-100 focus:bg-zinc-800 focus:text-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-amber-400/60 ' +
                className
            }
        >
            {children}
        </Link>
    );
};

Dropdown.Trigger = Trigger;
Dropdown.Content = Content;
Dropdown.Link = DropdownLink;

export default Dropdown;
