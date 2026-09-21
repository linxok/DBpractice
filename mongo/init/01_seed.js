const learn = db.getSiblingDB('learn');

learn.students.insertMany([
  {
    name: 'Олена Ковальчук',
    email: 'olena@example.com',
    city: 'Київ',
    courses: ['SQL', 'MongoDB'],
    grade: 92
  },
  {
    name: 'Ігор Мельник',
    email: 'ihor@example.com',
    city: 'Львів',
    courses: ['SQL', 'PostgreSQL'],
    grade: 85
  },
  {
    name: 'Марія Бондаренко',
    email: 'maria@example.com',
    city: 'Одеса',
    courses: ['MongoDB'],
    grade: 78
  }
]);

learn.courses.insertMany([
  { code: 'SQL', title: 'Основи SQL', hours: 40 },
  { code: 'PostgreSQL', title: 'PostgreSQL для початківців', hours: 32 },
  { code: 'MongoDB', title: 'Документо-орієнтовані бази даних', hours: 24 }
]);
