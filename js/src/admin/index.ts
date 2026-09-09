import app from 'flarum/admin/app';
import HashtagsPage from './components/HashtagsPage';

app.initializers.add('ernestdefoe-hashtags', () => {
  app.extensionData.for('ernestdefoe-hashtags').registerPage(HashtagsPage);
});
