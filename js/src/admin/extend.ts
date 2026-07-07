import Extend from 'flarum/common/extenders';
import ImporterPage from './components/ImporterPage';

declare const m: any;

export default [
  // The whole importer wizard lives on the extension's settings page.
  new Extend.Admin().customSetting(() => m(ImporterPage), 0),
];
