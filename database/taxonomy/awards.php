<?php

/**
 * Academic awards: the named qualification, its education level, and the field the name itself implies (when it does:
 * BEng is Engineering, BSc is not tied to any field). The generic words "degree", "diploma" and "certificate" are
 * education levels, not awards, and live in the Phase 6 dictionary.
 *
 * [key, name, level, implied field key or null, [aliases]]
 */
return [
    // bachelor's degrees
    ['beng', 'Bachelor of Engineering', 'bachelor', 'engineering', ['BEng', 'B.Eng', 'Bachelor of Engineering', 'Bachelor of Engineering Honours', 'BEng Hons']],
    ['bsc', 'Bachelor of Science', 'bachelor', null, ['BSc', 'B.Sc', 'Bachelor of Science', 'BSc Hons', 'Bachelor of Science Honours']],
    ['ba', 'Bachelor of Arts', 'bachelor', null, ['BA', 'B.A', 'Bachelor of Arts']],
    ['bcom', 'Bachelor of Commerce', 'bachelor', 'business-management', ['BCom', 'B.Com', 'Bachelor of Commerce']],
    ['bba', 'Bachelor of Business Administration', 'bachelor', 'business-administration', ['BBA', 'Bachelor of Business Administration', 'Bachelor of Business Management']],
    ['bacc', 'Bachelor of Accountancy', 'bachelor', 'accounting', ['BAcc', 'Bachelor of Accountancy', 'Bachelor of Accounting', 'Bachelor of Accounting and Finance']],
    ['llb', 'Bachelor of Laws', 'bachelor', 'law', ['LLB', 'LL.B', 'Bachelor of Laws', 'Bachelor of Law']],
    ['mbchb', 'Bachelor of Medicine and Bachelor of Surgery', 'bachelor', 'medicine', ['MBChB', 'MB ChB', 'Bachelor of Medicine and Bachelor of Surgery', 'Bachelor of Medicine and Surgery']],
    ['bed', 'Bachelor of Education', 'bachelor', 'education', ['BEd', 'B.Ed', 'Bachelor of Education', 'Bachelor of Education in Teaching']],
    ['btech', 'Bachelor of Technology', 'bachelor', null, ['BTech', 'B.Tech', 'Bachelor of Technology']],
    ['bpharm', 'Bachelor of Pharmacy', 'bachelor', 'pharmacy', ['BPharm', 'B.Pharm', 'Bachelor of Pharmacy']],
    ['bds', 'Bachelor of Dental Surgery', 'bachelor', 'dentistry', ['BDS', 'Bachelor of Dental Surgery']],
    ['bscn', 'Bachelor of Science in Nursing', 'bachelor', 'nursing', ['BScN', 'BSc Nursing', 'Bachelor of Science in Nursing', 'Bachelor of Nursing']],
    ['bsw', 'Bachelor of Social Work', 'bachelor', 'social-work', ['BSW', 'Bachelor of Social Work']],
    ['bvm', 'Bachelor of Veterinary Medicine', 'bachelor', 'veterinary-medicine', ['BVM', 'BVSc', 'Bachelor of Veterinary Medicine', 'Bachelor of Veterinary Science']],
    ['barch', 'Bachelor of Architecture', 'bachelor', 'architecture', ['BArch', 'B.Arch', 'Bachelor of Architecture']],
    ['bpa', 'Bachelor of Public Administration', 'bachelor', 'public-administration', ['BPA', 'Bachelor of Public Administration']],

    // postgraduate qualifications below a master's degree
    ['pgd', 'Postgraduate Diploma', 'postgraduate', null, ['PGD', 'PGDip', 'Postgraduate Diploma', 'Post Graduate Diploma', 'Post-graduate Diploma']],
    ['pgc', 'Postgraduate Certificate', 'postgraduate', null, ['PGC', 'PGCert', 'Postgraduate Certificate', 'Post Graduate Certificate']],
    ['pgce', 'Postgraduate Certificate in Education', 'postgraduate', 'education', ['PGCE', 'Postgraduate Certificate in Education', 'Post Graduate Certificate in Education']],

    // master's degrees
    ['msc', 'Master of Science', 'masters', null, ['MSc', 'M.Sc', 'Master of Science']],
    ['ma', 'Master of Arts', 'masters', null, ['MA', 'M.A', 'Master of Arts']],
    ['mba', 'Master of Business Administration', 'masters', 'business-administration', ['MBA', 'Master of Business Administration']],
    ['meng', 'Master of Engineering', 'masters', 'engineering', ['MEng', 'M.Eng', 'Master of Engineering']],
    ['mcom', 'Master of Commerce', 'masters', 'business-management', ['MCom', 'M.Com', 'Master of Commerce']],
    ['llm', 'Master of Laws', 'masters', 'law', ['LLM', 'LL.M', 'Master of Laws']],
    ['mph', 'Master of Public Health', 'masters', 'public-health', ['MPH', 'Master of Public Health']],
    ['med', 'Master of Education', 'masters', 'education', ['MEd', 'M.Ed', 'Master of Education']],
    ['mphil', 'Master of Philosophy', 'masters', null, ['MPhil', 'M.Phil', 'Master of Philosophy']],
    ['mpa', 'Master of Public Administration', 'masters', 'public-administration', ['MPA', 'Master of Public Administration']],
    ['mmed', 'Master of Medicine', 'masters', 'medicine', ['MMed', 'M.Med', 'Master of Medicine']],

    // doctorates
    ['phd', 'Doctor of Philosophy', 'phd', null, ['PhD', 'Ph.D', 'DPhil', 'Doctor of Philosophy', 'Doctorate', 'Doctoral degree']],

    // diplomas and certificates that are named awards
    ['hnd', 'Higher National Diploma', 'diploma', null, ['HND', 'Higher National Diploma']],
    ['advanced-diploma', 'Advanced Diploma', 'diploma', null, ['Advanced Diploma', 'Higher Diploma']],
    ['national-diploma', 'National Diploma', 'diploma', null, ['National Diploma']],
    ['technician-certificate', 'Technician Certificate', 'certificate', null, ['Technician Certificate', 'Craft Certificate', 'Technician Diploma Certificate']],
    ['grade-12', 'Grade 12 School Certificate', 'school', null, ['Grade 12', 'Grade 12 Certificate', 'School Certificate', 'Grade 12 School Certificate', 'GCE', 'O Level', 'O Levels', 'A Level', 'A Levels', 'Form 5', 'Secondary School Certificate']],
];
