 /* Load stylesheets and bootstrap */
require('styles/main.scss');
require('bootstrap-loader');
require('font-awesome/scss/font-awesome.scss');

/* Import headstart module to initiliase HeadstartFSM */
/* Notice 'import * as hs' -> hs.headstart is not read-only */
import * as hs from 'headstart';
window.namespace = "headstart";

hs.headstart = new hs.HeadstartFSM();
hs.headstart.start();