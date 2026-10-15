const admin = db.getSiblingDB('admin');
const username = process.env.MONGO_APP_USER;
const password = process.env.MONGO_APP_PASSWORD;
const roles = [
  { role: 'read', db: 'learn' },
  { role: 'read', db: 'shop_big' }
];

if (admin.getUser(username)) {
  admin.updateUser(username, { pwd: password, roles });
} else {
  admin.createUser({ user: username, pwd: password, roles });
}
