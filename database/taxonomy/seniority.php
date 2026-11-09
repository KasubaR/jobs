<?php

/**
 * Seniority, read from the words in a job title. "standard" has no words: it is what a title with none of these is.
 * [key, name, rank, words]
 */
return [
    ['intern', 'Intern or trainee', 1, ['intern', 'internship', 'trainee', 'graduate trainee', 'apprentice', 'attachment', 'industrial attachment']],
    ['junior', 'Junior', 2, ['junior', 'jnr', 'jr', 'entry level', 'graduate']],
    ['standard', 'Standard', 3, []],
    ['senior', 'Senior', 4, ['senior', 'snr', 'sr', 'principal', 'lead', 'team leader', 'supervisor', 'chief']],
    ['manager', 'Manager', 5, ['manager', 'mgr', 'superintendent']],
    ['head', 'Head', 6, ['head of']],
    ['director', 'Director', 7, ['director', 'executive director', 'managing director', 'chief executive', 'ceo', 'cfo', 'coo', 'cto']],
];
