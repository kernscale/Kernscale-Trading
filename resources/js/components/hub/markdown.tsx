import './markdown.css';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import { isPublicSource } from '@/components/hub/research-state';
export default function Markdown({ children }: { children: string }) {
    return (
        <div className="hub-markdown">
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                disallowedElements={['img']}
                urlTransform={(url) => (isPublicSource(url) ? url : undefined)}
                components={{
                    a: ({ href, children }) =>
                        href ? (
                            <a href={href} target="_blank" rel="noreferrer">
                                {children}
                            </a>
                        ) : (
                            <span>{children}</span>
                        ),
                    table: ({ children }) => (
                        <div className="max-w-full overflow-x-auto">
                            <table>{children}</table>
                        </div>
                    ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}
