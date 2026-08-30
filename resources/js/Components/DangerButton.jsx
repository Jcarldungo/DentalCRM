import Button from '@/Components/UI/Button';

/** Alias over Components/UI/Button — see PrimaryButton. */
export default function DangerButton({ className = '', disabled, children, ...props }) {
    return (
        <Button variant="danger-solid" className={className} disabled={disabled} {...props}>
            {children}
        </Button>
    );
}
