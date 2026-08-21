import { Store } from 'lucide-react';
import { branch } from '@/data/dummy';

export default function AppLogo() {
    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <Store className="size-4" />
            </div>
            <div className="ml-1 grid flex-1 text-left">
                <span className="truncate text-sm leading-tight font-semibold">myPOS</span>
                <span className="truncate text-2xs leading-tight text-muted-foreground">{branch.code} · Sumber Makmur</span>
            </div>
        </>
    );
}
