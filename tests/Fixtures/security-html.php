<?php

// Shared server and real-browser corpus. Browser checks run without CSP.
return [
    '<article><custom><p onclick="window.__xss=1">retained<img src="x" onerror="window.__xss=1"></p><script>window.__xss=1</script></custom></article>',
    '</div><div id="openwiki-root"><article><a href="jav&#x09;ascript:window.__xss=1">retained</a></article></div>',
    '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=window.__xss=1>"></math><p>retained</p>',
    '<svg><foreignObject><p><img src=x onerror=window.__xss=1></p></foreignObject></svg><p>retained</p>',
    '<table><caption><custom><img src=x onerror=window.__xss=1></custom></caption><tr><td>retained</td></tr></table>',
    '<noscript><p title="</noscript><img src=x onerror=window.__xss=1>">bad</p></noscript><p>retained</p>',
    '<template><custom><script>window.__xss=1</script></custom></template><p>retained</p>',
    '<section><custom><custom><a href="javascript:window.__xss=1" target="evil">retained</a><img src="data:image/svg+xml;base64,PHN2Zz4=" onload="window.__xss=1"></custom></custom></section>',
    '<p>retained<img src="/\\evil.test/x" width="999999" data-attachment-id="bad"><a href="//evil.test" target="_blank" rel="opener">link</a></p>',
];
