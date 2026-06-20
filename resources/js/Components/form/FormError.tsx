/**
 * Inline field error. Renders nothing when there is no message, so callers
 * can mount it unconditionally. The `id` lets the owning input point at it
 * via `aria-describedby`, and `role="alert"` announces it to assistive tech
 * (WCAG 2.2 — error identification + programmatic association).
 */
interface FormErrorProps {
    id: string;
    message?: string;
}

export default function FormError({ id, message }: FormErrorProps) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} role="alert" className="mt-1 text-sm font-medium text-danger">
            {message}
        </p>
    );
}
