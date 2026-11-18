import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { cn } from '@/lib/utils';

/** Two-step delete: the first click arms it, the second within 3 seconds confirms. */
export function ConfirmDelete({
    label,
    onConfirm,
    disabled,
    className,
}: {
    label: string;
    onConfirm: () => void;
    disabled?: boolean;
    className?: string;
}) {
    const [armed, setArmed] = useState(false);
    useEffect(() => {
        if (!armed) return;
        const id = setTimeout(() => setArmed(false), 3000);
        return () => clearTimeout(id);
    }, [armed]);
    return (
        <button
            type="button"
            aria-label={armed ? `Confirm: ${label}` : label}
            title={armed ? 'Click again to delete' : label}
            disabled={disabled}
            onClick={() => (armed ? onConfirm() : setArmed(true))}
            onBlur={() => setArmed(false)}
            className={cn(
                'kit-tool !h-7 !min-w-7 !px-1.5',
                armed &&
                    '!bg-red-500/15 !text-red-300 !opacity-100 hover:!bg-red-500/25',
                className,
            )}
        >
            <Trash2 className="size-3.5" />
            {armed && <span className="text-[12px]">Delete?</span>}
        </button>
    );
}
