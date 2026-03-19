import { localStorage, scrollTo, string } from 'lib/common/util';

export default {
    setFavicon(state, key){
        document.querySelector('link[type="image/x-icon"]').setAttribute('href', state.favicon[key]);
    },

    CheckAdBlock(state, data){
        state.adBlocked = data;
    },

    setL10n(state, params){
        if (!!params) {
            for (const key in params) {
                if (typeof state[key] !== 'undefined') {
                    state[key] = JSON.parse(JSON.stringify(params[key]));
                }
            }
        }
    },
};